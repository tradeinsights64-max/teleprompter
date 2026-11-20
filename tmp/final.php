<?php
$r = app(\App\Services\TemplateRenderer::class);
$s = app(\App\Services\Settings::class);
$keys = ['flow.branding','flow.welcome','flow.simple_start','flow.simple_offer','flow.freevip_pitch','flow.mentorship_intro','flow.mentorship_form','flow.account_path','flow.main_menu','flow.partner_change','flow.register'];
$fail = 0;
foreach ($keys as $k) foreach (['en','am'] as $l) {
  try {
    $x = $r->render($k, null, [], $l);
    $txt = $x['text']; $btn = $x['buttons'] ?? [];
    $bad = [];
    if (preg_match('/\{\{|\}\}/', $txt)) $bad[] = 'placeholder';
    if (preg_match('/12\+|forms\.gle|Trading Challenge/u', $txt)) $bad[] = 'old-text';
    foreach ($btn as $row) foreach ($row as $b) {
      if (empty($b['callback_data']) && empty($b['url'])) $bad[] = 'btn-no-action';
      if (!empty($b['url']) && (str_contains($b['url'], '{{') || !preg_match('#^https?://#', $b['url']))) $bad[] = 'btn-url';
      if (!empty($b['url']) && str_contains($b['url'], 'forms.gle')) $bad[] = 'google-form';
    }
    $n = count($btn);
    echo sprintf("%-22s %s len=%4d btnRows=%d %s\n", $k, $l, mb_strlen($txt), $n, $bad ? 'FAIL '.implode(',', array_unique($bad)) : 'OK');
    if ($bad) $fail++;
  } catch (\Throwable $e) { echo "$k $l ERROR ".$e->getMessage()."\n"; $fail++; }
}
echo "mode=", $s->get('flow.simple_onboarding'), " testers=", ($s->get('flow.simple_onboarding_testers') ?: 'admin ids'), " admins=", count(array_filter(explode(',', (string) $s->get('flow.admin_telegram_ids')))), "\n";
echo "form_url_host=", parse_url((string) $s->get('flow.mentorship_form_url'), PHP_URL_HOST), "\n";
$since = now()->subMinutes(30);
echo "render_failed_30m=", DB::table('events')->where('event_type','flow.render_failed')->where('created_at','>=',$since)->count(), "\n";
echo "send_failed_30m=", DB::table('events')->where('event_type','flow.send_failed')->where('created_at','>=',$since)->count(), "\n";
echo "updates_in_30m=", DB::table('telegram_activities')->where('direction','in')->where('created_at','>=',$since)->count(), " out=", DB::table('telegram_activities')->where('direction','out')->where('created_at','>=',$since)->count(), "\n";
echo "starts_30m=", DB::table('events')->where('event_type','flow.start')->where('created_at','>=',$since)->count(), "\n";
echo "TOTAL_FAIL=$fail\n";
