<?php

namespace App\Services\Telegram;

use App\Models\Member;
use App\Models\ServiceRule;
use App\Services\EventRecorder;
use App\Services\Settings;
use App\Services\TemplateRenderer;
use App\Services\TemplateRenderException;

/**
 * Renders and delivers a flow.* template to a member.
 *
 * All copy, buttons and links come from the database (message_templates +
 * settings) so an administrator can change them from the Bot Flow page
 * without a deploy.
 */
class FlowMessenger
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly TelegramClient $telegram,
        private readonly Settings $settings,
        private readonly EventRecorder $events,
        private readonly ActivityRecorder $activity,
    ) {}

    private ?string $lastRenderedText = null;

    /** The exact text of the most recent send() on this instance (null if it did not render). */
    public function lastRenderedText(): ?string
    {
        return $this->lastRenderedText;
    }

    /**
     * @param  array  $extra  additional {{variables}} for this send
     * @param  callable|null  $mutateButtons  receives the rendered keyboard rows
     *                                        and returns the rows to actually send
     */
    public function send(Member $member, string $templateKey, array $extra = [], ?callable $mutateButtons = null): bool
    {
        // Resolved once and reused below: withFlowExtras() decides the optional
        // video/guide lines *and* the branding image, so the send path must see
        // the same array the renderer did.
        $extra = $this->withFlowExtras($templateKey, $extra, $member);
        $this->lastRenderedText = null;

        try {
            $rendered = $this->renderer->render($templateKey, $member, $extra);
            // Issue 57: callers that must keep an exact copy of what was sent
            // (the IB partner-change warning) read it back via lastRenderedText().
            $this->lastRenderedText = $rendered['text'] ?? null;

            // A template with no buttons of its own can still be given some.
            if ($mutateButtons !== null) {
                $rendered['buttons'] = $mutateButtons($rendered['buttons'] ?? []) ?: null;
            }

            $rendered['buttons'] = $this->withoutRetiredButtons($rendered['buttons']);
            $rendered['buttons'] = $this->withMainMenuButton($templateKey, $rendered['buttons']);
        } catch (TemplateRenderException $e) {
            $this->events->system('flow.render_failed', $member->id, [
                'template' => $templateKey, 'error' => $e->getMessage(),
            ]);

            // A missing or unrenderable template would otherwise leave the
            // member with no reply at all, so make it loud on the dashboard.
            $this->activity->outbound('flow.render_failed', $member->telegram_id, $templateKey,
                'failed', $e->getMessage(), null, null, null, $member->id);

            return false;
        }

        try {
            // A template may carry an image (the branded opening screen does).
            // If the image cannot be delivered — bad URL, unreachable host —
            // fall back to text so the member is never left with nothing.
            $photo = $extra['photo'] ?? null;

            if (filled($photo)) {
                try {
                    $this->telegram->sendPhoto($member->telegram_id, $photo, $rendered['text'], $rendered['buttons']);

                    $this->activity->outbound('flow.'.str_replace('flow.', '', $templateKey), $member->telegram_id,
                        mb_substr($rendered['text'], 0, 200), 'success', null,
                        ['state' => $member->bot_state, 'template' => $templateKey, 'photo' => true],
                        null, null, $member->id);

                    return true;
                } catch (TelegramApiException $e) {
                    $this->events->system('flow.photo_failed', $member->id, [
                        'template' => $templateKey, 'photo' => $photo, 'error' => $e->getMessage(),
                    ]);
                    // fall through to the text-only send below
                }
            }

            $this->telegram->sendMessage($member->telegram_id, $rendered['text'], $rendered['buttons'], true);
        } catch (TelegramApiException $e) {
            $this->events->system('flow.send_failed', $member->id, [
                'template' => $templateKey, 'error' => $e->getMessage(),
            ]);
            $this->activity->outbound('flow.send_failed', $member->telegram_id, $templateKey,
                'failed', $e->getMessage(), null, null, null, $member->id);

            return false;
        }

        // Every delivered flow message is traceable per member on the
        // Telegram Integration feed, from language selection onwards.
        $this->activity->outbound('flow.'.str_replace('flow.', '', $templateKey), $member->telegram_id,
            mb_substr($rendered['text'], 0, 200), 'success', null,
            ['state' => $member->bot_state, 'template' => $templateKey], null, null, $member->id);

        return true;
    }

    /**
     * Screen → the setting holding its image. Every slot is optional: an
     * empty setting simply means that screen is sent as text.
     */
    private const IMAGE_SLOTS = [
        'flow.branding' => 'flow.brand_logo_url',
        'flow.simple_start' => 'flow.brand_logo_url',
        'flow.welcome' => 'flow.image_welcome',
        'flow.bootcamp_intro' => 'flow.image_bootcamp',
        'flow.challenge_intro' => 'flow.image_challenge',
        'flow.freevip_pitch' => 'flow.image_freevip',
        'flow.mentorship_intro' => 'flow.image_mentorship',
        'flow.verified' => 'flow.image_verified',
    ];

    /**
     * TI-005 — screens that come before a member has a menu to go back to,
     * plus the menu itself. Everything else gets the reusable Main Menu row.
     */
    private const NO_MAIN_MENU = [
        'flow.branding',
        'flow.language_prompt',
        'flow.simple_start',
        'flow.welcome',
        'flow.social',
        'flow.main_menu',
        'flow.verifying',
        'flow.email_invalid',
        'flow.join_denied',
    ];

    /**
     * The keyboard a template would be sent with — built exactly as send()
     * builds it, but returned instead of sent. Used to edit an already-sent
     * message's buttons in place (the social step's Checked toggles).
     *
     * @return array|null  null when the template cannot be rendered
     */
    public function keyboard(Member $member, string $templateKey, array $extra = [], ?callable $mutateButtons = null): ?array
    {
        $extra = $this->withFlowExtras($templateKey, $extra, $member);

        try {
            $rendered = $this->renderer->render($templateKey, $member, $extra);
        } catch (TemplateRenderException) {
            return null;
        }

        $buttons = $rendered['buttons'] ?? null;

        if ($mutateButtons !== null) {
            $buttons = $mutateButtons($buttons ?? []) ?: null;
        }

        $buttons = $this->withoutRetiredButtons($buttons);

        return $this->withMainMenuButton($templateKey, $buttons);
    }

    /**
     * Callbacks that no longer have a screen of their own. A button carrying
     * one is dropped at send time no matter which template it sits in, so a
     * database that has not yet had the clean-up migration run — or an admin
     * who re-adds the button by hand — still never shows it.
     *
     * svc:regional_vip — Regional Lifetime VIP is sold only through the
     * "I can't use this broker in my region" branch of the account-path step.
     */
    private const RETIRED_CALLBACKS = ['svc:regional_vip'];

    private function withoutRetiredButtons(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        $kept = [];

        foreach ($rows as $row) {
            $row = array_values(array_filter(
                (array) $row,
                fn ($button) => ! in_array($button['callback_data'] ?? null, self::RETIRED_CALLBACKS, true),
            ));

            if ($row !== []) {
                $kept[] = $row;
            }
        }

        return $kept === [] ? null : $kept;
    }

    /**
     * Append a single Main Menu row so the menu is reachable from anywhere
     * (TI-005), without duplicating it on screens that already offer one.
     */
    private function withMainMenuButton(string $templateKey, ?array $rows): ?array
    {
        if (in_array($templateKey, self::NO_MAIN_MENU, true)) {
            return $rows;
        }

        $rows ??= [];

        foreach ($rows as $row) {
            foreach ((array) $row as $button) {
                if (($button['callback_data'] ?? null) === 'menu') {
                    return $rows; // the template already provides one
                }
            }
        }

        $rows[] = [['text' => __('⬅ Main Menu'), 'callback_data' => 'menu']];

        return $rows;
    }

    /**
     * Optional video lines are rendered only when the admin has supplied a
     * URL — the source document leaves both video URLs undefined [GAP], so an
     * empty setting must not leave a dangling label in the message.
     */
    private function withFlowExtras(string $templateKey, array $extra, ?Member $member = null): array
    {
        // The registration screen's deposit sentence follows the rule of the
        // service the member is actually entering: Bootcamp is free and shows
        // nothing, while a service whose rule still demands a deposit shows
        // its own floor. The single space keeps the placeholder resolved
        // while adding nothing visible.
        if (! array_key_exists('deposit_line', $extra) && $templateKey === 'flow.register') {
            $rule = ServiceRule::for($member?->service_selected);

            $extra['deposit_line'] = $rule->requires_deposit
                ? ($member?->language === 'am'
                    ? sprintf('💰 ቢያንስ $%d ተቀማጭ ያስፈልጋል።', (int) $rule->minimumDeposit())
                    : sprintf('💰 A minimum deposit of $%d is required.', (int) $rule->minimumDeposit()))
                : ' ';
        }

        if (! array_key_exists('video_line', $extra)) {
            $url = match ($templateKey) {
                'flow.partner_change', 'flow.fail_partnership' => $this->settings->get('flow.video_partner_change'),
                'flow.register' => $this->settings->get('flow.video_register'),
                default => null,
            };

            // A single space keeps the placeholder resolved (never "unresolved")
            // while adding nothing visible to the message.
            $extra['video_line'] = filled($url) ? "🎥 Video guide: {$url}\n" : ' ';
        }

        // Screens that can carry a picture. The branding screen always has one
        // (falling back to the packaged logo); the rest show one only once an
        // administrator has filled the slot in.
        if (! array_key_exists('photo', $extra) && array_key_exists($templateKey, self::IMAGE_SLOTS)) {
            $image = $this->settings->get(self::IMAGE_SLOTS[$templateKey]);

            if (blank($image) && in_array($templateKey, ['flow.branding', 'flow.simple_start'], true)) {
                $image = rtrim((string) config('app.url'), '/').'/logo.jpg';
            }

            // Telegram fetches the URL itself, so a local address can never
            // work. Skip the doomed call and send the caption as text instead
            // of burning a failed API round trip on every /start in dev.
            $extra['photo'] = filled($image) && ! $this->isUnreachableByTelegram((string) $image)
                ? $image
                : null;
        }

        if (! array_key_exists('guide_line', $extra)) {
            $guide = $templateKey === 'flow.register' ? $this->settings->get('flow.guide_register') : null;
            $extra['guide_line'] = filled($guide) ? "📄 Step-by-step guide: {$guide}\n" : ' ';
        }

        return $extra;
    }

    /**
     * A photo may also be a Telegram file_id (no scheme) — those are always
     * fine. Only http(s) URLs pointing at a private/loopback host are refused.
     */
    private function isUnreachableByTelegram(string $photo): bool
    {
        $host = parse_url($photo, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return false;
        }

        return (bool) preg_match('~^(localhost|127\.|0\.0\.0\.0|\[::1\]|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)~i', $host)
            || str_ends_with(strtolower($host), '.local')
            || str_ends_with(strtolower($host), '.test');
    }
}
