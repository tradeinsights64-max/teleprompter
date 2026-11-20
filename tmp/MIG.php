<?php

use App\Models\MessageTemplate;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Simple onboarding (Nov 2026): two new screens and the switch that turns
 * them on. Nothing existing is changed; with the switch on "off" the bot
 * behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(['key' => 'flow.simple_onboarding'], [
            'value' => 'testers', 'type' => 'string', 'group' => 'flow',
            'label' => 'Simple onboarding (off / testers / all)',
            'description' => 'off = classic flow. testers = only the IDs in flow.simple_onboarding_testers (empty = admin IDs). all = everyone.',
        ]);
        Setting::firstOrCreate(['key' => 'flow.simple_onboarding_testers'], [
            'value' => null, 'type' => 'string', 'group' => 'flow',
            'label' => 'Simple onboarding testers (Telegram IDs, comma-separated)',
            'description' => 'Empty = the admin Telegram IDs.',
        ]);

        $lang = [[
            ['text' => '🇬🇧 English', 'callback_data' => 'lang:en'],
            ['text' => '🇪🇹 አማርኛ', 'callback_data' => 'lang:am'],
        ]];
        $startBody = "🏆 TRADE INSIGHT\n\nFree trading academy + VIP community.\nነጻ የtrading academy + VIP community።\n\nChoose your language · ቋንቋዎን ይምረጡ 👇";

        foreach (['en', 'am'] as $l) {
            MessageTemplate::firstOrCreate(['key' => 'flow.simple_start', 'language' => $l], [
                'title' => 'Simple onboarding 1 — Welcome + language',
                'body' => $startBody, 'buttons' => $lang, 'variables' => [], 'is_enabled' => true, 'version' => 1,
            ]);
        }

        MessageTemplate::firstOrCreate(['key' => 'flow.simple_offer', 'language' => 'en'], [
            'title' => 'Simple onboarding 2 — Offer + Exness question',
            'body' => "🎓 ALL COURSES + 💎 VIP — 100% FREE\n\nYou get:\n• All Academy courses (Amharic or English)\n• The VIP group — 12+ live sessions every week\n• Certificates\n\nWhat you need:\n• An Exness account under Trade Insight (KYC verified)\n• For the VIP group: at least \${{min_deposit}} deposited — the money stays in your own account\n\nDo you have an Exness account?",
            'buttons' => [
                [['text' => "🆕 No — open a new account", 'callback_data' => 'acct:none']],
                [['text' => '✅ Yes — I already have Exness', 'callback_data' => 'acct:has']],
                [['text' => "🌍 Exness doesn't work in my country", 'callback_data' => 'region']],
            ],
            'variables' => ['min_deposit'], 'is_enabled' => true, 'version' => 1,
        ]);

        MessageTemplate::firstOrCreate(['key' => 'flow.simple_offer', 'language' => 'am'], [
            'title' => 'Simple onboarding 2 — Offer + Exness question',
            'body' => "🎓 ሁሉም ኮርሶች + 💎 VIP — 100% በነጻ\n\nየሚያገኙት፦\n• ሁሉም የAcademy ኮርሶች (በአማርኛ ወይም በእንግሊዝኛ)\n• VIP group — በሳምንት ከ12 በላይ live sessions\n• Certificate\n\nየሚያስፈልገው፦\n• በTrade Insight ስር የተከፈተ Exness account (KYC የተረጋገጠ)\n• ለVIP group፦ ቢያንስ \${{min_deposit}} deposit — ገንዘቡ በራስዎ account ውስጥ ነው የሚቆየው\n\nExness account አለዎት?",
            'buttons' => [
                [['text' => '🆕 የለኝም — አዲስ account ልክፈት', 'callback_data' => 'acct:none']],
                [['text' => '✅ አለኝ — Exness account አለኝ', 'callback_data' => 'acct:has']],
                [['text' => '🌍 Exness በሀገሬ አይሰራም', 'callback_data' => 'region']],
            ],
            'variables' => ['min_deposit'], 'is_enabled' => true, 'version' => 1,
        ]);
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', ['flow.simple_start', 'flow.simple_offer'])->delete();
        Setting::whereIn('key', ['flow.simple_onboarding', 'flow.simple_onboarding_testers'])->delete();
    }
};
