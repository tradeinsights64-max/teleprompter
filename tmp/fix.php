<?php
use App\Models\MessageTemplate as T;
$s = app(\App\Services\Settings::class);
// 1) testers: admin ids + member 9606
$m = \App\Models\Member::find(9606);
$ids = array_filter(array_map('trim', explode(',', (string) $s->get('flow.admin_telegram_ids'))));
if ($m) $ids[] = (string) $m->telegram_id;
$s->set('flow.simple_onboarding_testers', implode(',', array_unique($ids)));
echo "testers=", count(array_unique($ids)), "\n";
// 2) branding screen off (Telegram shows the logo before Start already)
\App\Models\BotFlowStep::set(1, false);
echo "branding_on=", \App\Services\Telegram\BotFlow::enabled(1) ? 'yes' : 'no', "\n";
// 3) VIP schedule wording
file_put_contents('/root/backups/simple-onboarding-20261120/vip_pitch_before2.json', json_encode(T::where('key','flow.freevip_pitch')->get(['language','body']), JSON_UNESCAPED_UNICODE));
$rep = [
  'Morning Analysis (Tue–Fri, 8 AM)' => 'Morning Analysis (Tue–Fri, 8 AM · live on Wed & Fri)',
  'Bootcamp Q&A (Sat, 9 PM)' => 'Course Q&A (Sat, 9 PM)',
  'Morning Analysis (ከማክሰኞ–ዓርብ፣ ጠዋት 2 ሰዓት)' => 'Morning Analysis (ከማክሰኞ–ዓርብ፣ ጠዋት 2 ሰዓት · ረቡዕ እና ዓርብ live)',
  'Bootcamp Q&A (ቅዳሜ፣ ማታ 3 ሰዓት)' => 'የኮርስ Q&A (ቅዳሜ፣ ማታ 3 ሰዓት)',
];
foreach (T::where('key','flow.freevip_pitch')->get() as $t) { $t->body = strtr($t->body, $rep); $t->version++; $t->save(); echo $t->language, ': ', str_replace("\n", ' / ', mb_substr($t->body, 0, 260)), "\n"; }
