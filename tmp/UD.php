<?php

namespace App\Services\Telegram;

use App\Enums\MemberStatus;
use App\Jobs\VerifyFlowEmail;
use App\Models\Member;
use App\Models\MentorshipApplication;
use App\Models\Product;
use App\Models\SocialChannel;
use App\Models\SocialInteraction;
use App\Services\AdminReports;
use App\Services\ChallengeRegistry;
use App\Services\CommunityAccessService;
use App\Services\EmailNormalizer;
use App\Services\EventRecorder;
use App\Services\Access\AccessDecision;
use App\Services\Access\AccessPolicy;
use App\Services\ExnessVerifier;
use App\Services\InviteService;
use App\Services\Membership\MembershipSynchronizer;
use App\Services\Payments\PurchaseFlow;
use App\Services\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Trade Insight bot state machine.
 *
 * Implements tradeinsight_bot_decision_flow.md verbatim: language → welcome →
 * main menu → (Bootcamp | Challenge | Free VIP | Mentorship) → shared Exness
 * verification → personalised join button.
 *
 * State keys are the S_* names from the source document and are persisted on
 * members.bot_state so a user always resumes exactly where they left off.
 */
class UpdateDispatcher
{
    public const ALLOWED_UPDATES = ['message', 'callback_query', 'chat_member', 'my_chat_member', 'chat_join_request'];

    /** The only language codes the bot accepts (TI-002). */
    public const LANGUAGES = ['en', 'am'];

    // States (§ numbering follows the source document)
    public const S_BRANDING = 'S_BRANDING';

    public const S_LANGUAGE = 'S_LANGUAGE';

    public const S_WELCOME = 'S_WELCOME';

    public const S_SOCIAL = 'S_SOCIAL';

    public const S_MAIN_MENU = 'S_MAIN_MENU';

    public const S_BOOTCAMP_INTRO = 'S_BOOTCAMP_INTRO';

    public const S_CHALLENGE_RULES = 'S_CHALLENGE_RULES';

    public const S_PARTNER_PENDING = 'S_PARTNER_PENDING';

    public const S_CHALLENGE_INTRO = 'S_CHALLENGE_INTRO';

    public const S_FREEVIP_PITCH = 'S_FREEVIP_PITCH';

    public const S_ACCOUNT_PATH = 'S_ACCOUNT_PATH';

    /** Task 66: waiting for the applicant's country of residence (buttons or typed). */
    public const S_COUNTRY = 'S_COUNTRY';

    /** 2026-11-06: waiting for the phone number (Telegram's native Share Contact button). */
    public const S_CONTACT = 'S_CONTACT';

    public const S_PARTNER_CHANGE = 'S_PARTNER_CHANGE';

    public const S_REGISTER = 'S_REGISTER';

    public const S_AWAIT_EMAIL = 'S_AWAIT_EMAIL';

    public const S_VERIFY = 'S_VERIFY';

    public const S_VERIFIED = 'S_VERIFIED';

    public const S_MENTORSHIP_INTRO = 'S_MENTORSHIP_INTRO';

    public const S_MENTORSHIP_FORM = 'S_MENTORSHIP_FORM';

    public const S_AWAIT_MENTORSHIP_EMAIL = 'S_AWAIT_MENTORSHIP_EMAIL';

    public const S_MENTORSHIP_PENDING = 'S_MENTORSHIP_PENDING';

    /** An invoice is open and we are waiting for the provider to confirm it. */
    public const S_AWAIT_PAYMENT = 'S_AWAIT_PAYMENT';

    public const S_MEMBER = 'S_MEMBER';

    public function __construct(
        private readonly Settings $settings,
        private readonly FlowMessenger $flow,
        private readonly TelegramClient $telegram,
        private readonly EventRecorder $events,
        private readonly EmailNormalizer $emails,
        private readonly InviteService $invites,
        private readonly ActivityRecorder $activity,
        private readonly ChallengeRegistry $challenges,
        private readonly CommunityAccessService $access,
        private readonly MembershipSynchronizer $memberships,
        private readonly AccessPolicy $policy,
        private readonly PurchaseFlow $purchases,
        private readonly \App\Services\AuthLinkService $authLinks,
        private readonly \App\Services\MemberPromotion $promotion,
    ) {}

    public function dispatch(array $update): void
    {
        $this->recordInbound($update);

        // Issue 59: a message or button press from a private chat means the bot is not blocked.
        $from = $update['message']['chat'] ?? $update['callback_query']['message']['chat'] ?? null;
        if (($from['type'] ?? null) === 'private' && isset($from['id'])) {
            app(BotBlockTracker::class)->seen($from['id']);
        }

        try {
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            } elseif (isset($update['chat_member'])) {
                $this->handleChatMember($update['chat_member']);
            } elseif (isset($update['my_chat_member'])) {
                $this->handleMyChatMember($update['my_chat_member']);
            } elseif (isset($update['chat_join_request'])) {
                $this->handleJoinRequest($update['chat_join_request']);
            }
        } catch (Throwable $e) {
            Log::error('telegram.update_failed', [
                'update_id' => $update['update_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->activity->inbound(
                'update.processing_failed',
                $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null,
                null,
                'failed',
                $e->getMessage(),
                ['update_id' => $update['update_id'] ?? null],
            );
        }
    }

    // ==================================================================
    // Messages
    // ==================================================================

    private function handleMessage(array $message): void
    {
        if (($message['chat']['type'] ?? '') !== 'private') {
            return; // group chatter is not part of the flow
        }

        $from = $message['from'] ?? null;
        $text = trim($message['text'] ?? '');

        // 2026-11-06: a phone number shared with the Share Contact button has no text.
        if ($from !== null && ! ($from['is_bot'] ?? false) && isset($message['contact'])) {
            $this->receiveContact($this->findOrCreateMember($from), $message['contact'], (int) $from['id']);

            return;
        }

        if ($from === null || ($from['is_bot'] ?? false) || $text === '') {
            return;
        }

        // §14 admin commands are handled before the member state machine.
        if (str_starts_with($text, '/') && $this->handleAdminCommand((int) $from['id'], $text)) {
            return;
        }

        $member = $this->findOrCreateMember($from);

        // Issue 55: "My Status" works for everyone, blocked members included —
        // they are exactly the people who need to see why.
        if (preg_match('#^/(status|mystatus)(@\w+)?$#i', $text)) {
            $this->showMyStatus($member);

            return;
        }

        // 48-hour referral watch: once blocked, the bot does nothing else.
        if ($member->isReferralBlocked()) {
            $this->flow->send($member, 'flow.referral_blocked');

            return;
        }

        if (str_starts_with($text, '/start')) {
            /*
             * A website sign-in deep link arrives as "/start lk_<nonce>"
             * (MERGE_PLAN.md §E.3). Pin the nonce to this conversation, and
             * if the member is already a promoted user, complete the link on
             * the spot — a returning member should not have to re-verify to
             * get back into the website. A new member falls through into the
             * normal onboarding; the link completes when verification does.
             */
            $payload = trim(substr($text, strlen('/start')));

            // Issue 40: "/start tl_<nonce>" links a website account to this
            // Telegram account for mentorship notifications. Handled before
            // anything else and never falls into onboarding.
            $accountLinks = app(\App\Services\AccountLinkService::class);
            if ($payload !== '' && $accountLinks->isLinkPayload($payload)) {
                $this->telegram->sendMessage((int) $from['id'], $accountLinks->link($member, (int) $from['id'], $payload));

                return;
            }

            if ($payload !== '' && $this->authLinks->isAuthPayload($payload)) {
                $this->authLinks->attach($member, $payload);

                if ($member->user_id !== null) {
                    $this->authLinks->complete($member, (int) $member->user_id);
                    $this->flow->send($member, 'flow.web_login_done');

                    return;
                }
            }

            // Someone may already be inside Bootcamp, Challenge or Free VIP
            // and be opening the bot for the first time. Reconciling here — in
            // front of the existing flow, not in place of it — means they are
            // recognised as a member without leaving, rejoining or asking for
            // another invite. Everything after this point is unchanged.
            $this->syncMemberships($member);

            $this->stepBranding($member);

            return;
        }

        // TI-005: the menu is reachable by command as well as by button, from
        // any state — but not while the bot is waiting for a typed answer that
        // could legitimately begin with a slash.
        if (str_starts_with($text, '/menu')) {
            $this->stepMainMenu($member);

            return;
        }

        // Typed input only matters in the two states that expect it.
        match ($member->bot_state) {
            self::S_AWAIT_EMAIL => $this->receiveExnessEmail($member, $text),
            self::S_AWAIT_MENTORSHIP_EMAIL => $this->receiveMentorshipEmail($member, $text),
            self::S_COUNTRY => $this->receiveCountryText($member, $text),
            self::S_CONTACT => $this->receiveContactText($member, $text),
            // G2: any other free text re-renders the current state unchanged.
            default => $this->renderCurrentState($member),
        };
    }

    // ==================================================================
    // Callbacks
    // ==================================================================

    private function handleCallback(array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $from = $callback['from'] ?? null;

        if ($from === null) {
            return;
        }

        $member = $this->findOrCreateMember($from);

        // Issue 55: the status dashboard (and its refresh) stays available to
        // blocked members; every other button keeps refusing them below.
        if ($data === 'mystatus' || $data === 'mystatus:invites') {
            $this->answerCallback($callback);
            $data === 'mystatus' ? $this->showMyStatus($member) : $this->resendInvites($member);

            return;
        }

        // 48-hour referral watch: once blocked, every button is refused.
        if ($member->isReferralBlocked()) {
            $this->answerCallback($callback);
            $this->flow->send($member, 'flow.referral_blocked');

            return;
        }

        // "I've switched back" on the 48-hour warning answers with a toast
        // when there is nothing to do yet, so it is routed before the ack.
        if ($data === 'refcheck') {
            $this->referralRecheck($member, $callback);

            return;
        }

        // The social step's Checked toggles answer their own callback with a
        // toast, so they are routed before the generic acknowledgement.
        if (str_starts_with($data, 'soc:')) {
            $this->toggleSocialChannel($member, $callback, substr($data, 4));

            return;
        }

        try {
            $this->telegram->answerCallbackQuery($callback['id']);
        } catch (TelegramApiException) {
            // non-fatal
        }

        // G4 navigation
        if ($data === 'menu') {
            $this->stepMainMenu($member);

            return;
        }
        if ($data === 'back') {
            $this->stepBack($member);

            return;
        }

        [$verb, $arg] = array_pad(explode(':', $data, 2), 2, null);

        match ($verb) {
            'start_bot' => $this->stepLanguage($member),
            'lang' => $this->chooseLanguage($member, $arg),
            'welcome_continue' => $this->stepSocial($member),
            'social_continue' => $this->confirmSocials($member),
            'svc' => match ($arg) {
                'bootcamp_continue' => $this->stepAccountPath($member),
                // Legacy: the Main Menu button that used to sell Regional VIP
                // directly. It is no longer seeded, but keyboards already
                // sitting in chats still carry it — those taps land on the
                // one place the product is offered now.
                'regional_vip' => $this->stepRegionRestricted($member),
                // "Set up my Exness account" on the Challenge gate — the
                // existing activation flow, entered at its usual first step.
                'challenge_setup' => $this->startExnessSetupForChallenge($member),
                default => $this->chooseService($member, $arg),
            },
            // Paid products. Buying is always a request to create an invoice;
            // nothing is granted here.
            'buy' => $this->startPurchase($member, $arg),
            'pay' => $arg === 'check' ? $this->purchases->recheck($member) : null,
            'chal' => $arg === 'accept'
                ? $this->acceptChallengeRules($member)
                : $this->chooseChallengeCategory($member, $arg),
            'vip' => $this->stepAccountPath($member),
            'acct' => $this->chooseAccountPath($member, $arg),
            'ctry' => $this->chooseCountry($member, $arg),
            'region' => $this->chooseRegion($member),
            'submit_email', 'resubmit' => $this->stepAwaitEmail($member),
            // Legacy "I have fixed it" button on the old partner-removed
            // message: re-run verification against the stored email.
            'partner_recheck' => $this->rerunVerification($member),
            'mentor' => $this->mentorship($member, $arg),
            default => null,
        };
    }

    // ==================================================================
    // §14 — admin commands
    // ==================================================================

    /**
     * @return bool true when the text was consumed as an admin command
     */
    private function handleAdminCommand(int $telegramId, string $text): bool
    {
        $command = strtolower(strtok(ltrim($text, '/'), " \n@"));
        $argument = trim(mb_substr($text, mb_strlen(strtok($text, " \n"))));

        $adminCommands = ['reports', 'highvolume', 'balance', 'registrations', 'user'];

        if (! in_array($command, $adminCommands, true)) {
            return false;
        }

        // The whole admin command surface can be switched off on the board.
        if (! BotFlow::enabled(BotFlow::STEP_ADMIN_REPORTS)) {
            return false;
        }

        // §14 auth gate: unknown senders are ignored with a notice.
        if (! $this->isAdmin($telegramId)) {
            $this->events->system('admin_command.unauthorized', null, [
                'telegram_id' => $telegramId, 'command' => $command,
            ]);
            $this->sendRaw($telegramId, __('Not authorized.'));

            return true;
        }

        $reports = app(AdminReports::class);

        $body = match ($command) {
            'reports' => implode("\n", [
                '🗂 '.__('Admin reports'),
                '',
                '/highvolume — '.__('High Volume Client Report'),
                '/balance — '.__('Community Balance Status Report'),
                '/registrations — '.__('Registration Report'),
                '/user <email|username> — '.__('Individual User Report'),
            ]),
            'highvolume' => $reports->highVolumeText(),
            'balance' => $reports->communityBalanceText(),
            'registrations' => $reports->registrationsText(),
            'user' => $argument === ''
                ? __('Usage: /user <email or telegram username>')
                : $reports->userText($argument),
        };

        $this->sendRaw($telegramId, $body);

        $this->events->system('admin_command.executed', null, [
            'telegram_id' => $telegramId, 'command' => $command,
        ]);

        return true;
    }

    private function isAdmin(int $telegramId): bool
    {
        $ids = collect(explode(',', (string) $this->settings->get('flow.admin_telegram_ids')))
            ->map(fn ($id) => trim($id))
            ->filter()
            ->all();

        return in_array((string) $telegramId, $ids, true);
    }

    private function sendRaw(int|string $chatId, string $text): void
    {
        try {
            $this->telegram->sendMessage($chatId, $text, null, true);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.admin_reply_failed', ['error' => $e->getMessage()]);
        }
    }

    // ==================================================================
    // Step 1 — language (§3)
    // ==================================================================

    /**
     * TI-001 — the branded opening screen: logo, brand name, bot name, Start.
     * Shown to every user on /start; pressing Start opens language selection.
     */
    private function stepBranding(Member $member): void
    {
        // Simple onboarding (Nov 2026): one welcome + language screen.
        if ($this->simpleMode($member)) {
            $this->stepSimpleStart($member);

            return;
        }

        if (! BotFlow::enabled(BotFlow::STEP_BRANDING)) {
            $this->stepLanguage($member);

            return;
        }

        $member->update(['bot_state' => self::S_BRANDING]);
        $this->events->system('flow.branding_shown', $member->id, [
            'returning' => (bool) $member->language_selected,
        ]);

        $this->flow->send($member, 'flow.branding');
    }

    /**
     * Simple onboarding switch (setting flow.simple_onboarding):
     * off = classic flow · testers = only the Telegram IDs in
     * flow.simple_onboarding_testers (empty → the admin IDs) · all = everyone.
     */
    private function simpleMode(Member $member): bool
    {
        $mode = strtolower(trim((string) $this->settings->get('flow.simple_onboarding', 'off')));

        if ($mode === 'all') {
            return true;
        }

        if ($mode !== 'testers') {
            return false;
        }

        $list = (string) ($this->settings->get('flow.simple_onboarding_testers') ?: $this->settings->get('flow.admin_telegram_ids'));
        $ids = collect(explode(',', $list))->map(fn ($id) => trim($id))->filter()->all();

        return in_array((string) $member->telegram_id, $ids, true);
    }

    /** Simple onboarding, screen 1: logo + one line + language buttons. */
    private function stepSimpleStart(Member $member): void
    {
        $member->update(['bot_state' => self::S_LANGUAGE]);
        $this->events->system('flow.start', $member->id, ['returning' => $member->language_selected, 'simple' => true]);
        $this->flow->send($member, 'flow.simple_start');
    }

    /**
     * Simple onboarding, after the language: members who already have access
     * get the menu; everyone else goes to the offer + Exness question with
     * Free VIP as their service (the same route the old VIP pitch used).
     */
    private function afterLanguageSimple(Member $member): void
    {
        $member->refresh();
        $hasAccess = $member->user_id !== null || in_array($member->status?->value, ['verified', 'vip_active'], true);

        if ($hasAccess) {
            $this->stepMainMenu($member);

            return;
        }

        if ($member->service_selected === null) {
            $member->update(['service_selected' => 'free_vip']);
            $this->events->system('flow.service_selected', $member->id, ['service' => 'free_vip', 'simple' => true]);
        }

        $this->stepAccountPath($member);
    }

    private function stepLanguage(Member $member): void
    {
        // Re-issued /start keeps the record and returns to language choice.
        $member->update(['bot_state' => self::S_LANGUAGE]);
        $this->events->system('flow.start', $member->id, ['returning' => $member->language_selected]);
        $this->flow->send($member, 'flow.language_prompt');
    }

    /**
     * TI-002 — the choice is bound to the Telegram user, persisted with the
     * moment it was made, and applied to every later message.
     */
    private function chooseLanguage(Member $member, ?string $lang): void
    {
        // Never trust callback data: only the two offered codes are accepted.
        if (! in_array($lang, self::LANGUAGES, true)) {
            $this->events->system('flow.language_rejected', $member->id, ['submitted' => $lang]);
            $this->renderCurrentState($member);

            return;
        }

        $previous = $member->language;

        $member->update([
            'language' => $lang,
            'language_selected' => true,
            'language_selected_at' => now(),
        ]);

        $this->applyLocale($member);

        $this->events->system('flow.language_selected', $member->id, [
            'language' => $lang,
            'previous' => $previous,
            'changed' => $previous !== $lang,
            'selected_at' => $member->language_selected_at?->toIso8601String(),
        ]);

        // Simple onboarding: straight to the offer + "Do you have Exness?".
        // Same Free VIP route and the same Exness check as before — only the
        // welcome, social and menu screens in between are left out.
        if ($this->simpleMode($member)) {
            $this->afterLanguageSimple($member);

            return;
        }

        $this->stepWelcome($member);
    }

    /**
     * Align Laravel's locale with the member so any __() strings the bot emits
     * outside a template (errors, admin replies) match the chosen language.
     */
    private function applyLocale(Member $member): void
    {
        $lang = in_array($member->language, self::LANGUAGES, true) ? $member->language : 'en';

        app()->setLocale($lang);
    }

    // ==================================================================
    // Step 3 — welcome / community introduction (TI-003)
    // ==================================================================

    /**
     * The introduction is a screen the user acknowledges, not a banner that
     * scrolls past: Continue is what moves them on to the social channels.
     */
    private function stepWelcome(Member $member): void
    {
        // Switched off on the board → straight to the social step.
        if (! BotFlow::enabled(BotFlow::STEP_WELCOME)) {
            $this->stepSocial($member);

            return;
        }

        $member->update(['bot_state' => self::S_WELCOME]);
        $this->flow->send($member, 'flow.welcome');
    }

    // ==================================================================
    // Step 4 — follow us (TI-004); buttons come from social_channels
    // ==================================================================

    private function stepSocial(Member $member): void
    {
        if (! BotFlow::enabled(BotFlow::STEP_SOCIAL)) {
            $this->stepMainMenu($member);

            return;
        }

        $channels = SocialChannel::forBot();

        // Nothing configured (or every channel hidden) — never show an empty
        // screen, just carry on to the menu.
        if ($channels->isEmpty()) {
            $this->events->system('flow.social_skipped', $member->id, ['reason' => 'no enabled channels']);
            $this->stepMainMenu($member);

            return;
        }

        $member->update(['bot_state' => self::S_SOCIAL]);

        // One row per channel, in admin order, then Continue from the
        // template so its caption stays editable per language.
        $this->flow->send($member, 'flow.social', [], $this->socialKeyboard($member, $channels));
    }

    /**
     * The step-4 keyboard: for every channel a row of two buttons — the link
     * itself, and a "☐ Not checked / ✅ Checked" toggle showing whether this
     * member has already ticked it.
     *
     * Telegram gives a bot no signal when a URL button is opened, so the tick
     * is the member's own — tapped once they have followed. It is what makes
     * the screen remember, per member, what has been done.
     *
     * @param  \Illuminate\Support\Collection<int, SocialChannel>  $channels
     * @return callable(array): array  a $mutateButtons closure for FlowMessenger
     */
    private function socialKeyboard(Member $member, $channels): callable
    {
        $checked = SocialInteraction::checkedMapFor($member);
        $language = (string) $member->language;

        return function (array $rows) use ($channels, $checked, $language) {
            $links = $channels
                ->map(fn (SocialChannel $c) => [
                    ['text' => $c->caption($language), 'url' => $c->url],
                    [
                        'text' => ($checked[$c->id] ?? false) ? __('✅ Checked') : __('☐ Not checked'),
                        'callback_data' => 'soc:'.$c->id,
                    ],
                ])
                ->all();

            return array_merge($links, $rows);
        };
    }

    /**
     * A channel's Checked toggle was tapped.
     *
     * Records the tap (state row + event history), flips the button in place
     * on the very message that was tapped, and confirms with a toast. Nothing
     * else about the flow moves: the member stays on the social step until
     * they press Continue.
     */
    private function toggleSocialChannel(Member $member, array $callback, string $arg): void
    {
        $channel = ctype_digit($arg) ? SocialChannel::find((int) $arg) : null;

        if ($channel === null || ! $channel->is_enabled) {
            $this->answerCallback($callback, __('This channel is no longer available.'));
            $this->stepSocial($member);

            return;
        }

        $interaction = SocialInteraction::toggle($member, $channel);
        $isChecked = $interaction->isChecked();

        $this->events->system($isChecked ? 'social.checked' : 'social.unchecked', $member->id, [
            'channel' => $channel->slug,
            'channel_id' => $channel->id,
            'click_count' => $interaction->click_count,
        ]);

        $channels = SocialChannel::forBot();
        $done = SocialInteraction::where('member_id', $member->id)
            ->where('status', SocialInteraction::CHECKED)
            ->whereIn('social_channel_id', $channels->pluck('id'))
            ->count();

        $this->answerCallback($callback, $isChecked
            ? __(':channel — ✅ Checked (:done of :total)', ['channel' => $channel->caption((string) $member->language), 'done' => $done, 'total' => $channels->count()])
            : __(':channel — ☐ Not checked', ['channel' => $channel->caption((string) $member->language)]));

        // Flip the button on the tapped message. If that message can no
        // longer be edited (too old, deleted), send the screen afresh so the
        // member always ends up looking at the current state.
        $chatId = $callback['message']['chat']['id'] ?? null;
        $messageId = $callback['message']['message_id'] ?? null;
        $rows = $this->flow->keyboard($member, 'flow.social', [], $this->socialKeyboard($member, $channels));

        if ($chatId === null || $messageId === null || $rows === null) {
            $this->stepSocial($member);

            return;
        }

        try {
            $this->telegram->editMessageReplyMarkup($chatId, (int) $messageId, $rows);
        } catch (TelegramApiException) {
            $this->stepSocial($member);
        }
    }

    /**
     * Continue pressed on the social step. Following is not verifiable by
     * Telegram and is therefore never enforced — the moment is simply
     * recorded, once, and the member goes on to the menu.
     */
    private function confirmSocials(Member $member): void
    {
        if ($member->socials_confirmed_at === null) {
            $member->update(['socials_confirmed_at' => now()]);
            $this->events->system('social.continued', $member->id, [
                'checked' => SocialInteraction::where('member_id', $member->id)
                    ->where('status', SocialInteraction::CHECKED)->count(),
            ]);
        }

        $this->stepMainMenu($member);
    }

    /**
     * The member says they moved their Exness referral back. Ask the broker
     * now rather than making them wait for the next scheduled re-check;
     * ReferralWatch sends the restored / still-moved / unavailable reply.
     */
    private function referralRecheck(Member $member, array $callback): void
    {
        $outcome = app(\App\Services\ReferralWatch::class)->recheckFromBot($member);
        $am = $member->language === 'am';

        $this->answerCallback($callback, match ($outcome) {
            'throttled' => $am ? 'እባክዎ እንደገና ከመፈተሽዎ በፊት ሁለት ደቂቃ ይጠብቁ።' : 'Please wait a couple of minutes before checking again.',
            'none' => $am ? 'መዳረሻዎ ንቁ ነው — የሚፈተሽ ነገር የለም።' : 'Your access is active — nothing to check.',
            default => null,
        });
    }

    private function answerCallback(array $callback, ?string $text = null): void
    {
        try {
            $this->telegram->answerCallbackQuery($callback['id'], $text);
        } catch (TelegramApiException) {
            // non-fatal
        }
    }

    // ==================================================================
    // Step 5 — main menu (§5)
    // ==================================================================

    private function stepMainMenu(Member $member): void
    {
        $member->update(['bot_state' => self::S_MAIN_MENU]);

        // Services whose step is switched off are dropped from the keyboard.
        $this->flow->send($member, 'flow.main_menu', [], function (array $rows) use ($member) {
            $disabled = array_filter([
                BotFlow::STEP_CHALLENGE => 'svc:challenge',
                BotFlow::STEP_FREEVIP => 'svc:free_vip',
                BotFlow::STEP_MENTORSHIP_INTRO => 'svc:mentorship',
            ], fn ($step) => ! BotFlow::enabled($step), ARRAY_FILTER_USE_KEY);

            $statusRow = [['text' => $member->language === 'am' ? '📋 የእኔ ሁኔታ' : '📋 My Status', 'callback_data' => 'mystatus']];

            if ($disabled === []) {
                return array_merge([$statusRow], $rows);
            }

            return array_merge([$statusRow], array_values(array_filter(array_map(
                fn (array $row) => array_values(array_filter(
                    $row,
                    fn (array $btn) => ! in_array($btn['callback_data'] ?? '', $disabled, true),
                )),
                $rows,
            ), fn (array $row) => $row !== [])));
        });
    }

    // ==================================================================
    // Issue 55 — My Status dashboard
    // ==================================================================

    /** The member's own onboarding/membership status, read live from the database. */
    private function showMyStatus(Member $member): void
    {
        $report = app(MemberStatusReport::class)->build($member->fresh());

        try {
            $this->telegram->sendMessage($member->telegram_id, $report['text'], $report['buttons'], true);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.status_send_failed', ['member_id' => $member->id, 'error' => $e->getMessage()]);
        }

        $this->events->system('member.status_viewed', $member->id, [
            'summary' => array_map(fn ($r) => $r[0].' '.$r[1], $report['rows']),
        ]);
    }

    /** "Send my group invite links" — only for members the existing rules already admit. */
    private function resendInvites(Member $member): void
    {
        $member = $member->fresh();
        $qualified = $member->user_id !== null || in_array($member->status?->value, ['verified', 'vip_active'], true);

        if ($member->isBlocked() || $member->isReferralBlocked() || ! $qualified) {
            $this->showMyStatus($member);

            return;
        }

        app(\App\Services\CommunityInvite::class)->send($member);
    }

    private function chooseService(Member $member, ?string $service): void
    {
        if (! in_array($service, ['bootcamp', 'challenge', 'free_vip', 'mentorship'], true)) {
            return;
        }

        // Guard against a stale keyboard pointing at a switched-off service.
        $stepForService = ['challenge' => BotFlow::STEP_CHALLENGE, 'free_vip' => BotFlow::STEP_FREEVIP, 'mentorship' => BotFlow::STEP_MENTORSHIP_INTRO][$service] ?? null;

        if ($stepForService !== null && ! BotFlow::enabled($stepForService)) {
            $this->stepMainMenu($member);

            return;
        }

        $member->update(['service_selected' => $service]);
        $this->events->system('flow.service_selected', $member->id, ['service' => $service]);

        match ($service) {
            // TI-016: Bootcamp gets its own information screen before the
            // shared Exness flow, like the other services already had.
            'bootcamp' => $this->stepBootcampIntro($member),
            'challenge' => $this->stepChallenge($member),
            'free_vip' => $this->stepFreeVipPitch($member),
            'mentorship' => $this->stepMentorshipIntro($member),
        };
    }

    /**
     * The Challenge, behind its eligibility gate.
     *
     * An active Exness account is mandatory and nothing substitutes for it —
     * not Regional VIP, not mentorship, not any future paid product. The
     * policy is consulted rather than reimplemented here.
     *
     * The gate only refuses when we positively know the account is not active.
     * A member we have never checked continues into the existing flow exactly
     * as before: that flow *is* how an account gets verified, and short-
     * circuiting it would break onboarding for every new entrant.
     */
    private function stepChallenge(Member $member): void
    {
        $decision = $this->policy->check($member, AccessPolicy::FEATURE_CHALLENGE);

        if ($decision->outcome === AccessDecision::REQUIRES_EXNESS) {
            $this->events->system('access.challenge_blocked', $member->id, $decision->toArray());

            // Their other access is untouched, and the message says so.
            $this->flow->send($member, 'flow.challenge_needs_exness');

            return;
        }

        /*
         * Since the merge the challenge is a real row the backend admin owns
         * (RULE 10), addressed by its season label. No row carries the label,
         * or the admin has not enabled Telegram enrolment → there is nothing
         * to join. Say so at the FIRST screen rather than walking someone
         * through category selection and the rules, then refusing at the end.
         */
        if ($this->challenges->challenge() === null) {
            $this->events->system('challenge.unavailable_shown', $member->id, [
                'season' => $this->challenges->season(),
            ]);

            $this->flow->send($member, 'flow.challenge_unavailable');

            return;
        }

        $this->stepChallengeIntro($member);
    }

    private function stepBootcampIntro(Member $member): void
    {
        $member->update(['bot_state' => self::S_BOOTCAMP_INTRO]);
        $this->flow->send($member, 'flow.bootcamp_intro');
    }

    // ==================================================================
    // Paid products
    // ==================================================================

    /**
     * A purchase button was pressed.
     *
     * Creates an invoice and nothing else. Access follows the provider
     * confirming the payment, never this tap — which is why no state here
     * grants anything.
     */
    private function startPurchase(Member $member, ?string $slot): void
    {
        if (! in_array($slot, ['regional_vip', 'mentorship'], true)) {
            return;
        }

        $order = $this->purchases->start($member, $slot);

        if ($order !== null) {
            $member->update(['bot_state' => self::S_AWAIT_PAYMENT]);

            $this->events->system('flow.purchase_started', $member->id, [
                'slot' => $slot,
                'order' => $order->reference,
            ]);
        }
    }

    /**
     * From the Challenge gate into the existing Exness activation flow.
     *
     * Deliberately reuses the account-path step rather than adding a parallel
     * route: this is the same flow every other service already sends members
     * through, and it stays the only way an account is verified.
     */
    private function startExnessSetupForChallenge(Member $member): void
    {
        $member->update(['service_selected' => 'challenge']);
        $this->stepAccountPath($member);
    }

    // ==================================================================
    // Step 4C — challenge (§8)
    // ==================================================================

    private function stepChallengeIntro(Member $member): void
    {
        $member->update(['bot_state' => self::S_CHALLENGE_INTRO]);
        $this->flow->send($member, 'flow.challenge_intro');
    }

    private function chooseChallengeCategory(Member $member, ?string $category): void
    {
        if (! in_array($category, ['real', 'demo'], true)) {
            return;
        }

        // §8.1 — one category, ever. A different choice is refused, not switched.
        if ($member->challenge_category !== null && $member->challenge_category !== $category) {
            $this->events->system('flow.challenge_category_locked', $member->id, [
                'existing' => $member->challenge_category, 'attempted' => $category,
            ]);
            $this->flow->send($member, 'flow.challenge_locked');

            return;
        }

        if ($member->challenge_category === null) {
            $member->update(['challenge_category' => $category]);
            $this->events->system('flow.challenge_category_set', $member->id, ['category' => $category]);
        }

        // TI-018: rules are shown and accepted before verification, so nobody
        // reaches an entry without having agreed to the terms it is judged by.
        $this->stepChallengeRules($member);
    }

    private function stepChallengeRules(Member $member): void
    {
        $member->update(['bot_state' => self::S_CHALLENGE_RULES]);
        $this->flow->send($member, 'flow.challenge_rules');
    }

    /**
     * TI-018 — acceptance is recorded with its timestamp before the member is
     * sent into the shared Exness flow.
     */
    private function acceptChallengeRules(Member $member): void
    {
        if ($member->challenge_category === null) {
            $this->stepChallengeIntro($member);

            return;
        }

        // The season can close between opening the rules screen and accepting
        // them. Distinguished from a clash because they are different problems
        // for the member: one is "come back later", the other is "this identity
        // is already entered".
        if ($this->challenges->challenge() === null) {
            $this->flow->send($member, 'flow.challenge_unavailable');

            return;
        }

        $entry = $this->challenges->register($member, rulesAccepted: true);

        if ($entry === null) {
            // A clash — the same identity is already entered this season, or
            // the category is locked to the other one.
            $this->flow->send($member, 'flow.fail_service_conditions');

            return;
        }

        $this->stepAccountPath($member);
    }

    // ==================================================================
    // Step 4V — free VIP (§9)
    // ==================================================================

    private function stepFreeVipPitch(Member $member): void
    {
        $member->update(['bot_state' => self::S_FREEVIP_PITCH]);
        $this->flow->send($member, 'flow.freevip_pitch');
    }

    // ==================================================================
    // Steps 5-9 — shared Exness subflow (§6)
    // ==================================================================

    private function stepAccountPath(Member $member): void
    {
        // Task 66: ask the country of residence once, right before the Exness
        // step. Answering, skipping or typing it all lead straight back here.
        $country = app(\App\Services\CountryCheck::class);
        if ($country->shouldAsk($member)) {
            $member->update(['bot_state' => self::S_COUNTRY]);
            $country->ask($member);

            return;
        }

        // 2026-11-06: ask once for the phone number with Telegram's native
        // Share Contact button. Sharing or skipping both come straight back here.
        $profile = app(\App\Services\OnboardingProfile::class);
        if ($profile->shouldAskPhone($member)) {
            $member->update(['bot_state' => self::S_CONTACT]);
            $profile->askPhone($member);

            return;
        }

        $member->update([
            'bot_state' => self::S_ACCOUNT_PATH,
            // §6.1 side effect: starts the 3/6/9-day reminder clock.
            'onboarding_started_at' => $member->onboarding_started_at ?? now(),
            'started_at' => $member->started_at ?? now(),
        ]);

        $this->flow->send($member, $this->simpleMode($member) ? 'flow.simple_offer' : 'flow.account_path');
    }

    /** Task 66: a country button (ctry:XX), "other" (type it) or "skip". */
    private function chooseCountry(Member $member, ?string $arg): void
    {
        $country = app(\App\Services\CountryCheck::class);
        if (! \App\Services\CountryCheck::ready()) {
            return;
        }

        if ($arg === 'other') {
            $member->update(['bot_state' => self::S_COUNTRY]);
            $country->askTyped($member);

            return;
        }

        $cc = $arg === 'skip' ? null : strtoupper((string) $arg);
        if ($cc !== null && ! in_array($cc, $country->codes(), true)) {
            return;
        }

        $waiting = $member->bot_state === self::S_COUNTRY;
        $country->declare($member, $cc);

        // An old keyboard tapped later only updates the answer; it never
        // pulls the member back to the account step from wherever they are.
        if ($waiting) {
            $this->stepAccountPath($member->fresh());
        }
    }

    private function receiveCountryText(Member $member, string $text): void
    {
        $country = app(\App\Services\CountryCheck::class);
        $cc = $country->matchTyped($text);

        if ($cc === null) {
            $this->sendRaw($member->telegram_id, $country->unknownText($member));
            $country->askTyped($member);

            return;
        }

        $country->declare($member, $cc);
        $this->stepAccountPath($member->fresh());
    }

    /** 2026-11-06: a shared contact (any state); in S_CONTACT the flow then moves on. */
    private function receiveContact(Member $member, array $contact, int $fromId): void
    {
        $profile = app(\App\Services\OnboardingProfile::class);
        $stored = $profile->receiveContact($member, $contact, $fromId);

        if ($member->bot_state === self::S_CONTACT) {
            $stored ? $this->stepAccountPath($member->fresh()) : $profile->askPhone($member);
        }
    }

    /** S_CONTACT and the member typed instead of sharing: "Skip" moves on, anything else re-asks. */
    private function receiveContactText(Member $member, string $text): void
    {
        $profile = app(\App\Services\OnboardingProfile::class);
        if ($profile->isSkip($member, $text)) {
            $profile->skipPhone($member);
            $this->stepAccountPath($member->fresh());

            return;
        }
        $profile->askPhone($member);
    }

    /** "My region doesn't support Exness": the existing offer, then the location review. */
    private function chooseRegion(Member $member): void
    {
        $this->stepRegionRestricted($member);
        app(\App\Services\OnboardingProfile::class)->answer($member->fresh(), 'region');
    }

    private function chooseAccountPath(Member $member, ?string $path): void
    {
        if (in_array($path, ['has', 'none'], true)) {
            app(\App\Services\OnboardingProfile::class)->answer($member, $path === 'has' ? 'yes' : 'no');
            $member->refresh();
        }

        match ($path) {
            'has' => $this->stepPartnerChange($member),
            'none' => $this->stepRegister($member),
            default => null,
        };
    }

    private function stepPartnerChange(Member $member): void
    {
        $member->update(['account_path' => 'has_account', 'bot_state' => self::S_PARTNER_CHANGE]);
        $this->flow->send($member, 'flow.partner_change');
    }

    private function stepRegister(Member $member): void
    {
        $member->update(['account_path' => 'no_account', 'bot_state' => self::S_REGISTER]);
        $this->flow->send($member, 'flow.register');
    }

    /**
     * "I can't use this broker in my region" — the third account-path option,
     * and the one place Regional Lifetime VIP is offered.
     *
     * This used to be a dead end: a support link and nothing else. For someone
     * whose region Exness does not serve, every Exness-gated service is
     * permanently out of reach, so the honest answer is the route that does
     * not need a broker account at all.
     *
     * Regional Lifetime VIP is exactly that product, and 1-to-1 Mentorship has
     * never required Exness either. Both are offered here. The Challenge is
     * not, and cannot be — it needs an active Exness account, and no purchase
     * substitutes for one.
     *
     * Someone who already holds Regional VIP sees their access, not the pitch.
     */
    /** Country Review decision "$300 offer": same step as tapping "my region". */
    public function offerRegionalFromReview(Member $member): void
    {
        $this->stepRegionRestricted($member);
    }

    /** Country Review decision "free course": the normal Exness registration step. */
    public function registerFromReview(Member $member): void
    {
        $this->stepRegister($member);
    }

    private function stepRegionRestricted(Member $member): void
    {
        $member->update(['account_path' => 'region_restricted']);

        $this->events->system('flow.region_restricted', $member->id, [
            'service' => $member->service_selected,
        ]);

        if ($this->policy->hasRegionalVip($member)) {
            $this->flow->send($member, 'flow.vip_purchase_confirmed', [
                'product' => __('Regional Lifetime VIP'),
                'order_reference' => $member->entitlements()->live()
                    ->granting('regional_vip')->latest('id')->first()?->order?->reference ?? '—',
            ]);

            return;
        }

        $vip = $this->purchases->productFor('regional_vip');

        // Without payments configured this behaves exactly as it always has,
        // so a deployment with no Crypto Pay token is unchanged.
        if (! $this->purchases->enabled() || $vip === null) {
            $this->flow->send($member, 'flow.region_restricted');

            return;
        }

        $mentorship = $this->purchases->productFor('mentorship');

        $this->flow->send($member, 'flow.region_paid_options', [
            'vip_price' => (string) $vip->price_usd,
            'mentorship_price' => (string) ($mentorship?->price_usd ?? ''),
        ]);
    }

    private function stepAwaitEmail(Member $member): void
    {
        $member->update(['bot_state' => self::S_AWAIT_EMAIL, 'verification_status' => 'pending_email']);
        $this->flow->send($member, 'flow.await_email');
    }

    private function rerunVerification(Member $member): void
    {
        if ($member->exness_email === null) {
            $this->stepAwaitEmail($member);

            return;
        }

        $member->update(['bot_state' => self::S_VERIFY]);
        $this->flow->send($member, 'flow.verifying');
        $this->events->system('flow.self_recheck_requested', $member->id);

        $outcome = app(ExnessVerifier::class)->verify($member, $member->exness_email, interactive: true);

        if ($outcome === ExnessVerifier::OUTCOME_UNREACHABLE) {
            $this->flow->send($member, 'flow.api_error');
            VerifyFlowEmail::dispatch($member->id, $member->exness_email);
        }
    }

    private function receiveExnessEmail(Member $member, string $text): void
    {
        $email = $this->emails->normalize($text);

        // §6.4 — format check first: no API call is consumed on malformed input.
        if (! $this->emails->isValidFormat($email)) {
            $this->flow->send($member, 'flow.email_invalid');

            return;
        }

        $member->update(['bot_state' => self::S_VERIFY]);
        $this->flow->send($member, 'flow.verifying');

        // members.exness_email is unique. Writing it before the engine has
        // ruled on the claim would throw on a second Telegram account using
        // the same address — and a swallowed SQL error leaves that person
        // with no reply at all. Store it only while it is genuinely free;
        // the engine still decides, and still answers, either way.
        if (! Member::where('id', '!=', $member->id)->where('exness_email', $email)->exists()) {
            try {
                $member->update(['exness_email' => $email]);
            } catch (UniqueConstraintViolationException) {
                // Claimed between the check and the write — the engine's own
                // duplicate guard handles it from here.
            }
        }

        // Verify immediately, on a short budget, so the answer arrives in
        // seconds. Only an unreachable broker falls back to the queue — the
        // member is never left waiting on a worker that may not be running.
        $outcome = app(ExnessVerifier::class)->verify($member, $email, interactive: true);

        if ($outcome === ExnessVerifier::OUTCOME_UNREACHABLE) {
            $this->flow->send($member, 'flow.api_error');
            VerifyFlowEmail::dispatch($member->id, $email);
        }
    }

    // ==================================================================
    // Steps 15-17 — mentorship (§10); no Exness verification
    // ==================================================================

    private function stepMentorshipIntro(Member $member): void
    {
        $member->update(['bot_state' => self::S_MENTORSHIP_INTRO]);
        $this->flow->send($member, 'flow.mentorship_intro');
    }

    private function mentorship(Member $member, ?string $action): void
    {
        match ($action) {
            'online' => $this->stepMentorshipForm($member),
            'form_done' => $this->stepMentorshipEmail($member),
            'paid' => $this->claimMentorshipPayment($member),
            default => null,
        };
    }

    private function stepMentorshipForm(Member $member): void
    {
        $member->update([
            'bot_state' => self::S_MENTORSHIP_FORM,
            'onboarding_started_at' => $member->onboarding_started_at ?? now(),
            'started_at' => $member->started_at ?? now(),
        ]);
        $this->flow->send($member, 'flow.mentorship_form');
    }

    private function stepMentorshipEmail(Member $member): void
    {
        $member->update(['bot_state' => self::S_AWAIT_MENTORSHIP_EMAIL]);
        $this->flow->send($member, 'flow.mentorship_email');
    }

    private function receiveMentorshipEmail(Member $member, string $text): void
    {
        $email = $this->emails->normalize($text);

        if (! $this->emails->isValidFormat($email)) {
            $this->flow->send($member, 'flow.email_invalid');

            return;
        }

        // Never overwrite a verified Exness address with a contact address.
        $member->update([
            'exness_email' => $member->exness_email ?? (
                Member::where('id', '!=', $member->id)->where('exness_email', $email)->exists() ? null : $email
            ),
            'mentorship_form_submitted' => true,
            'bot_state' => self::S_MENTORSHIP_PENDING,
        ]);

        // TI-021: the application is a record, not just a message. Payment,
        // review and scheduling are tracked on it from here on.
        $application = MentorshipApplication::updateOrCreate(
            ['member_id' => $member->id],
            [
                'email' => $email,
                'format' => 'online',
                // The product catalogue is where the price is edited; the old
                // setting remains the fallback for an unseeded deployment.
                'price_usd' => Product::priceFor(Product::GRANT_MENTORSHIP)
                    ?? $this->settings->int('flow.mentorship_price_usd', 1000),
                'form_submitted' => true,
                'form_submitted_at' => now(),
            ],
        );

        $this->events->system('flow.mentorship_applied', $member->id, [
            'email' => $email,
            'application_id' => $application->id,
            'price_usd' => $application->price_usd,
        ]);

        // The application is complete; payment is the next step. Crypto Pay
        // is offered when it is configured and the product is on sale;
        // otherwise the existing manual instructions still stand, so a
        // deployment without a payment token keeps working exactly as before.
        if ($this->purchases->enabled() && $this->purchases->productFor('mentorship') !== null) {
            $this->purchases->offer($member, 'mentorship', 'flow.mentorship_payment_required');

            return;
        }

        $this->flow->send($member, 'flow.mentorship_payment');
    }

    /**
     * TI-021 — the member says they have paid. That is a claim to be checked,
     * never an approval: access still waits for an administrator.
     */
    private function claimMentorshipPayment(Member $member): void
    {
        $application = MentorshipApplication::where('member_id', $member->id)->first();

        if ($application === null) {
            $this->stepMentorshipIntro($member);

            return;
        }

        if ($application->payment_status === MentorshipApplication::PAYMENT_UNPAID) {
            $application->update([
                'payment_status' => MentorshipApplication::PAYMENT_CLAIMED,
                'payment_claimed_at' => now(),
            ]);

            $this->events->system('flow.mentorship_payment_claimed', $member->id, [
                'application_id' => $application->id,
            ]);
        }

        $this->flow->send($member, 'flow.mentorship_pending_review');
    }

    // ==================================================================
    // G4 — back / re-render
    // ==================================================================

    private function stepBack(Member $member): void
    {
        match ($member->bot_state) {
            self::S_SOCIAL => $this->stepWelcome($member),
            self::S_PARTNER_CHANGE, self::S_REGISTER => $this->stepAccountPath($member),
            self::S_AWAIT_EMAIL => $member->account_path === 'has_account'
                ? $this->stepPartnerChange($member)
                : $this->stepRegister($member),
            self::S_MENTORSHIP_FORM => $this->stepMentorshipIntro($member),
            default => $this->stepMainMenu($member),
        };
    }

    /**
     * G2/G3 — re-render whatever the member is currently sitting in.
     */
    private function renderCurrentState(Member $member): void
    {
        match ($member->bot_state) {
            self::S_BRANDING => $this->flow->send($member, 'flow.branding'),
            self::S_LANGUAGE, null => $this->flow->send($member, $this->simpleMode($member) ? 'flow.simple_start' : 'flow.language_prompt'),
            // These two carry their own buttons (Continue / the channel list),
            // so they are re-sent through their step rather than raw.
            self::S_WELCOME => $this->stepWelcome($member),
            self::S_SOCIAL => $this->stepSocial($member),
            self::S_MAIN_MENU => $this->stepMainMenu($member),
            self::S_BOOTCAMP_INTRO => $this->flow->send($member, 'flow.bootcamp_intro'),
            self::S_CHALLENGE_INTRO => $this->flow->send($member, 'flow.challenge_intro'),
            self::S_CHALLENGE_RULES => $this->flow->send($member, 'flow.challenge_rules'),
            self::S_PARTNER_PENDING => $this->flow->send($member, 'flow.partner_pending'),
            self::S_FREEVIP_PITCH => $this->flow->send($member, 'flow.freevip_pitch'),
            self::S_ACCOUNT_PATH => $this->flow->send($member, $this->simpleMode($member) ? 'flow.simple_offer' : 'flow.account_path'),
            self::S_COUNTRY => app(\App\Services\CountryCheck::class)->ask($member),
            self::S_CONTACT => app(\App\Services\OnboardingProfile::class)->askPhone($member),
            self::S_PARTNER_CHANGE => $this->flow->send($member, 'flow.partner_change'),
            self::S_REGISTER => $this->flow->send($member, 'flow.register'),
            self::S_AWAIT_EMAIL => $this->flow->send($member, 'flow.await_email'),
            self::S_MENTORSHIP_INTRO => $this->flow->send($member, 'flow.mentorship_intro'),
            self::S_MENTORSHIP_FORM => $this->flow->send($member, 'flow.mentorship_form'),
            self::S_AWAIT_MENTORSHIP_EMAIL => $this->flow->send($member, 'flow.mentorship_email'),
            self::S_MENTORSHIP_PENDING => $this->flow->send($member, 'flow.mentorship_payment'),
            default => $this->stepMainMenu($member),
        };
    }

    // ==================================================================
    // Group join — G6 ownership check (§6.5.d)
    // ==================================================================

    /**
     * 2026-11-20 security: someone used a group's join-request link. Approve
     * only people the bot knows and who qualify for that group (Exness rules,
     * a bought Academy + VIP or mentorship, or an admin); decline everyone
     * else and tell them how to get in through the bot. Forwarded links no
     * longer let scammers into the member groups.
     */
    private function handleJoinRequest(array $req): void
    {
        $chatId = (string) ($req['chat']['id'] ?? '');
        $userId = $req['from']['id'] ?? null;
        if ($chatId === '' || $userId === null || ! in_array($chatId, $this->access->knownChatIds(), true)) {
            return;
        }

        $service = app(\App\Services\Membership\GroupConfiguration::class)->serviceForChatId($chatId);
        $member = Member::where('telegram_id', $userId)->first();
        $admins = collect(explode(',', (string) $this->settings->get('flow.admin_telegram_ids')))->map(fn ($x) => trim($x))->filter();

        $ok = $admins->contains((string) $userId)
            || ($member && ($member->status?->value ?? null) !== 'kicked_discipline' && (
                $member->qualifiesFor($service)
                || $this->policy->hasRegionalVip($member)
                || $this->policy->hasMentorship($member)
            ));

        try {
            if ($ok) {
                $this->telegram->approveChatJoinRequest($chatId, $userId);
            } else {
                $this->telegram->declineChatJoinRequest($chatId, $userId);
                $dm = $req['user_chat_id'] ?? $userId;
                $this->telegram->sendMessage($dm,
                    "This group is for verified Trade Insight members only. Open @TradeInsightOfficialBot, tap Start and finish the steps — the bot sends your personal link.\n\n"
                    ."ይህ group የተረጋገጡ የTrade Insight አባላት ብቻ ነው። @TradeInsightOfficialBot ከፍተው Start ይጫኑ፤ ደረጃዎቹን ሲጨርሱ bot የግል link ይልክልዎታል።");
            }
        } catch (Throwable $e) {
            Log::warning('telegram.join_request_failed', ['chat' => $chatId, 'user' => $userId, 'error' => $e->getMessage()]);
        }

        $this->events->system($ok ? 'group.join_request_approved' : 'group.join_request_declined', $member?->id, [
            'service' => $service, 'telegram_id' => $userId,
        ]);
    }

    private function handleChatMember(array $chatMember): void
    {
        $chatId = (string) ($chatMember['chat']['id'] ?? '');

        // Any group this deployment runs, not just the one named in .env —
        // Bootcamp, Challenge and VIP may each have their own.
        if ($chatId === '' || ! in_array($chatId, $this->access->knownChatIds(), true)) {
            return;
        }

        $newStatus = $chatMember['new_chat_member']['status'] ?? '';
        $oldStatus = $chatMember['old_chat_member']['status'] ?? '';
        $joined = in_array($newStatus, ['member', 'administrator'], true)
            && ! in_array($oldStatus, ['member', 'administrator', 'creator'], true);

        $userId = $chatMember['new_chat_member']['user']['id'] ?? null;
        $viaLink = $chatMember['invite_link']['invite_link'] ?? null;

        if ($userId === null) {
            return;
        }

        $joiner = Member::where('telegram_id', $userId)->first();

        // Someone leaving is the other half of the membership record, and the
        // only signal we get without polling. The onboarding flow below is
        // untouched by it.
        if (! $joined) {
            if (in_array($newStatus, ['left', 'kicked'], true)) {
                $this->memberships->recordLeaveEvent($userId, $chatId, $joiner, $newStatus);

                // A ban is recorded and nothing more. What it should mean for
                // the member's standing in the bot — whether it blocks
                // re-verification, cancels a challenge entry, or ends a paid
                // entitlement — is not defined by the specification, which
                // marks it REQUIRES CONFIRMATION. Acting on it would be a
                // guess, so the event exists to be reviewed instead.
                if ($newStatus === 'kicked') {
                    $this->events->system('membership.removed_from_group', $joiner?->id, [
                        'telegram_id' => $userId,
                        'chat_id' => $chatId,
                        'removed_by' => $chatMember['from']['id'] ?? null,
                        'note' => 'REQUIRES CONFIRMATION — no further consequence is applied',
                    ]);
                }
            }

            return;
        }

        $linkOwner = $viaLink ? Member::where('invite_link', $viaLink)->first() : null;

        // G6 — the invite is bound to one telegram_id.
        if ($linkOwner !== null && (int) $linkOwner->telegram_id !== (int) $userId) {
            try {
                $this->telegram->kickAndUnban($chatId, (int) $userId);
                $this->telegram->revokeChatInviteLink($chatId, $viaLink);
            } catch (TelegramApiException $e) {
                Log::warning('telegram.link_mismatch_removal_failed', ['error' => $e->getMessage()]);
            }

            if ($joiner !== null) {
                $this->flow->send($joiner, 'flow.join_denied');
            }

            $this->events->system('invite.link_mismatch', $linkOwner->id, [
                'issued_to_telegram_id' => $linkOwner->telegram_id,
                'consumed_by_telegram_id' => $userId,
                'invite_link' => $viaLink,
                'action' => 'joiner removed, link revoked, admin attention required',
            ]);

            return;
        }

        $memberJoining = $linkOwner ?? $joiner;

        // Record the membership before anything else can return early. A join
        // by someone with no member row is precisely the case the activation
        // flow exists for: the row is written now, and linked to their profile
        // the first time they open the bot.
        $this->memberships->recordJoinEvent($userId, $chatId, $memberJoining, $newStatus);

        if ($memberJoining === null) {
            $this->events->system('group.unknown_joiner', null, ['telegram_id' => $userId, 'invite_link' => $viaLink]);

            return;
        }

        $memberJoining->update([
            'status' => MemberStatus::VipActive,
            'bot_state' => self::S_MEMBER,
            'joined_group_at' => $memberJoining->joined_group_at ?? now(),
            'invite_link_used' => true,
            'onboarding_completed' => true, // stops the reminder engine (§11)
        ]);

        $this->events->system('group.joined', $memberJoining->id, ['telegram_id' => $userId, 'via_link' => $viaLink]);

        // TI-022: the access record is closed out here — the grant becomes a
        // confirmed membership and the link that admitted them is revoked.
        $this->access->confirmJoin(
            $memberJoining->fresh(),
            $chatId,
            $chatMember['chat']['title'] ?? null,
        );
    }

    // ==================================================================

    /**
     * Reconcile this member against the managed groups.
     *
     * Advisory, never blocking: onboarding proceeds whatever Telegram says or
     * fails to say. A membership we cannot confirm is left as it was, and a
     * sync that throws is logged rather than surfaced — the user is trying to
     * start the bot, not to run a diagnostic.
     */
    private function syncMemberships(Member $member): void
    {
        if (! $this->settings->bool('membership.sync_on_start', true)) {
            return;
        }

        try {
            $this->memberships->sync($member);
        } catch (Throwable $e) {
            Log::warning('membership.sync_failed', [
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findOrCreateMember(array $from): Member
    {
        $member = Member::firstOrCreate(
            ['telegram_id' => $from['id']],
            [
                'telegram_username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'full_name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: null,
                // TI-002: English is the recommended default until the user
                // picks; language_selected stays false so the prompt still runs.
                'language' => 'en',
                'language_selected' => false,
                'status' => MemberStatus::Started,
                'bot_state' => self::S_LANGUAGE,
            ],
        );

        $member->fill([
            'telegram_username' => $from['username'] ?? $member->telegram_username,
            'first_name' => $from['first_name'] ?? $member->first_name,
        ]);

        if ($member->isDirty()) {
            $member->save();
        }

        // 2026-11-06: chat id + last activity, for every user with or without a username.
        app(\App\Services\OnboardingProfile::class)->touch($member, (int) $from['id']);

        // Everything rendered for this update follows the member's language.
        $this->applyLocale($member);

        return $member;
    }

    /**
     * Issue 59: the bot's own membership in a private chat changed —
     * "kicked" means the user blocked the bot, "member" that they unblocked
     * or restarted it. Group membership changes are not ours to track here.
     */
    private function handleMyChatMember(array $u): void
    {
        if (($u['chat']['type'] ?? null) !== 'private') {
            return;
        }
        $status = $u['new_chat_member']['status'] ?? null;
        $at = isset($u['date']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $u['date']) : null;
        $tracker = app(BotBlockTracker::class);

        if ($status === 'kicked') {
            $tracker->blocked($u['chat']['id'], 'my_chat_member', $at, 'User blocked the bot');
        } elseif ($status === 'member') {
            $tracker->unblocked($u['chat']['id'], 'my_chat_member', $at, 'User unblocked / restarted the bot');
        }
    }

    private function recordInbound(array $update): void
    {
        $type = collect(['message', 'callback_query', 'chat_member', 'my_chat_member'])
            ->first(fn ($k) => isset($update[$k])) ?? 'other';

        [$chatId, $content] = match ($type) {
            'message' => [$update['message']['chat']['id'] ?? null, $update['message']['text'] ?? '(non-text message)'],
            'callback_query' => [$update['callback_query']['message']['chat']['id'] ?? null, 'callback: '.($update['callback_query']['data'] ?? '')],
            'chat_member', 'my_chat_member' => [$update[$type]['chat']['id'] ?? null, $type.': '.($update[$type]['new_chat_member']['status'] ?? '')],
            default => [null, 'update keys: '.implode(',', array_keys($update))],
        };

        $this->activity->inbound('update.'.$type, $chatId, $content, 'success', null, [
            'update_id' => $update['update_id'] ?? null,
        ]);
    }
}
