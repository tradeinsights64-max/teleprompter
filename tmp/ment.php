<?php
use App\Models\MessageTemplate as T;
use App\Models\Setting;

$keys = ['flow.mentorship_intro', 'flow.mentorship_form', 'flow.freevip_pitch'];
file_put_contents('/root/backups/simple-onboarding-20261120/mentorship_before.json', json_encode([
    'templates' => T::whereIn('key', $keys)->get(['key', 'language', 'body', 'buttons', 'version']),
    'form_url' => Setting::where('key', 'flow.mentorship_form_url')->value('value'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// 1) Google form -> WhatsApp (the WhatsApp bot puts every lead in the CRM)
$wa = 'https://wa.me/251942612312?text=' . rawurlencode('Hi Trade Insight, I want to apply for the 1-on-1 mentorship');
app(\App\Services\Settings::class)->set('flow.mentorship_form_url', $wa);

$intro = [
    'en' => "👤 1-ON-1 MENTORSHIP WITH MOLLA\n\n12 weeks, one-to-one, built on the NLM strategy:\n• Weeks 1–2: mindset and foundations\n• Weeks 3–6: NLM technical edge\n• Weeks 7–8: trading together with Molla\n• Weeks 9–12: trading on your own, with review\n\n💵 \${{mentorship_price}} · Education only — no guaranteed profits.\n\nTap below to apply 👇",
    'am' => "👤 1-ለ-1 MENTORSHIP ከሞላ ጋር\n\n12 ሳምንት፣ አንድ ለአንድ፣ በNLM strategy ላይ የተመሰረተ፦\n• ሳምንት 1–2፦ mindset እና መሰረታዊ እውቀት\n• ሳምንት 3–6፦ የNLM technical edge\n• ሳምንት 7–8፦ ከሞላ ጋር አብሮ trade ማድረግ\n• ሳምንት 9–12፦ በራስዎ trade ማድረግ፣ ከreview ጋር\n\n💵 \${{mentorship_price}} · ትምህርት ብቻ — የትርፍ ዋስትና የለም።\n\nለማመልከት ከታች ይጫኑ 👇",
];
$applyBtn = ['en' => '✅ Apply now', 'am' => '✅ አሁን ያመልክቱ'];

$form = [
    'en' => "📩 APPLY ON WHATSAPP\n\nSend us a message on WhatsApp — our team will reply and guide you.\n\nAfter you send it, tap the button below.",
    'am' => "📩 በWhatsApp ያመልክቱ\n\nበWhatsApp መልዕክት ይላኩልን — ቡድናችን መልስ ሰጥቶ ይመራዎታል።\n\nከላኩ በኋላ ከታች ያለውን ቁልፍ ይጫኑ።",
];
$formBtns = [
    'en' => ['💬 Apply on WhatsApp', "✅ I've sent the message"],
    'am' => ['💬 በWhatsApp ያመልክቱ', '✅ መልዕክቱን ልኬያለሁ'],
];

foreach (['en', 'am'] as $l) {
    if ($t = T::where('key', 'flow.mentorship_intro')->where('language', $l)->first()) {
        $b = $t->buttons ?? [];
        foreach ($b as $i => $row) foreach ($row as $j => $btn) {
            if (($btn['callback_data'] ?? '') === 'mentor:online') $b[$i][$j]['text'] = $applyBtn[$l];
        }
        $t->body = $intro[$l]; $t->buttons = $b; $t->version++; $t->save();
        echo "intro $l ok\n";
    }
    if ($t = T::where('key', 'flow.mentorship_form')->where('language', $l)->first()) {
        $back = null;
        foreach (($t->buttons ?? []) as $row) foreach ($row as $btn) if (($btn['callback_data'] ?? '') === 'back') $back = $btn;
        $rows = [
            [['text' => $formBtns[$l][0], 'url' => '{{mentorship_form_url}}']],
            [['text' => $formBtns[$l][1], 'callback_data' => 'mentor:form_done']],
        ];
        if ($back) $rows[] = [$back];
        $t->body = $form[$l]; $t->buttons = $rows; $t->version++; $t->save();
        echo "form $l ok\n";
    }
    // 2) Sunday outlook time
    if ($t = T::where('key', 'flow.freevip_pitch')->where('language', $l)->first()) {
        $t->body = str_replace(['Next-week market outlook (Sun)', 'የሚቀጥለው ሳምንት የማርኬት outlook (እሁድ)'], ['Next-week market outlook (Sun, 9 PM)', 'የሚቀጥለው ሳምንት የማርኬት outlook (እሁድ፣ ማታ 3 ሰዓት)'], $t->body);
        $t->save(); echo "vip $l ", str_contains($t->body, '9 PM') || str_contains($t->body, 'እሁድ፣ ማታ') ? 'ok' : 'CHECK', "\n";
    }
}

$r = app(\App\Services\TemplateRenderer::class);
$x = $r->render('flow.mentorship_form', null, [], 'en');
echo 'FORM BTN URL host: ', parse_url($x['buttons'][0][0]['url'] ?? '', PHP_URL_HOST), "\n";
$y = $r->render('flow.mentorship_intro', null, [], 'en');
echo 'PRICE LINE: ', collect(explode("\n", $y['text']))->first(fn ($s) => str_contains($s, '💵')), "\n";
