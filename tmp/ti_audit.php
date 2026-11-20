<?php
// read-only usage audit — prints compact stats
$d30 = now()->subDays(30); $d7 = now()->subDays(7); $o = [];
$q = function ($k, $f) use (&$o) { try { $o[$k] = $f(); } catch (\Throwable $e) { $o[$k] = 'ERR ' . substr($e->getMessage(), 0, 80); } };
$cnt = fn($t, $col = 'created_at') => DB::table($t)->count() . ' | 30d ' . DB::table($t)->where($col, '>=', now()->subDays(30))->count() . ' | 7d ' . DB::table($t)->where($col, '>=', now()->subDays(7))->count();
foreach (['users','lesson_checklists','quiz_results','issued_certificates','journal_trades','journal_accounts','disc_assessments','challenge_registrations','mentorship_applications','support_tickets','contact_messages','newsletters','orders','payments','members','bot_block_events','visits','course_enrollments','follow_ups','social_interactions','country_location_tokens','student_reviews','wishlists','course_reviews'] as $t) $q($t, fn() => $cnt($t));
$q('learners_30d', fn() => DB::table('lesson_checklists')->where('created_at','>=',$d30)->distinct()->count('user_id'));
$q('learners_7d', fn() => DB::table('lesson_checklists')->where('created_at','>=',$d7)->distinct()->count('user_id'));
$q('journal_users_all', fn() => DB::table('journal_trades')->distinct()->count('user_id'));
$q('journal_users_30d', fn() => DB::table('journal_trades')->where('created_at','>=',$d30)->distinct()->count('user_id'));
$q('disc_users_30d', fn() => DB::table('disc_assessments')->where('created_at','>=',$d30)->distinct()->count('user_id'));
$q('certs_by_course_top', fn() => json_encode(DB::table('issued_certificates')->select('course_id', DB::raw('count(*) c'))->groupBy('course_id')->orderByDesc('c')->limit(8)->pluck('c','course_id')));
$q('visits_cols', fn() => implode(',', Schema::getColumnListing('visits')));
$q('members_cols', fn() => implode(',', Schema::getColumnListing('members')));
$q('events_cols', fn() => implode(',', Schema::getColumnListing('events')));
$q('tact_cols', fn() => implode(',', Schema::getColumnListing('telegram_activities')));
foreach (['orders'=>['payment_status','status'],'mentorship_applications'=>['status'],'support_tickets'=>['status'],'members'=>['status','flow_state','stage','vip_status']] as $t => $cols) {
  foreach ($cols as $c) $q("$t.$c", function () use ($t, $c) { if (!Schema::hasColumn($t, $c)) return 'n/a'; return json_encode(DB::table($t)->select($c, DB::raw('count(*) n'))->groupBy($c)->orderByDesc('n')->limit(15)->pluck('n', $c)); });
}
$q('events_top30d', function () use ($d30) { $c = collect(['name','event','event_name','type'])->first(fn($x) => Schema::hasColumn('events', $x)); return $c ? json_encode(DB::table('events')->where('created_at','>=',$d30)->select($c, DB::raw('count(*) n'))->groupBy($c)->orderByDesc('n')->limit(25)->pluck('n', $c)) : 'nocol'; });
$q('tact_top30d', function () use ($d30) { $c = collect(['action','type','activity','event'])->first(fn($x) => Schema::hasColumn('telegram_activities', $x)); return $c ? json_encode(DB::table('telegram_activities')->where('created_at','>=',$d30)->select($c, DB::raw('count(*) n'))->groupBy($c)->orderByDesc('n')->limit(25)->pluck('n', $c)) : 'nocol'; });
$q('visits_top30d', function () use ($d30) { $c = collect(['path','url','page','route'])->first(fn($x) => Schema::hasColumn('visits', $x)); return $c ? json_encode(DB::table('visits')->where('created_at','>=',$d30)->select($c, DB::raw('count(*) n'))->groupBy($c)->orderByDesc('n')->limit(20)->pluck('n', $c)) : 'nocol'; });
$q('blogs_views', fn() => Schema::hasColumn('blogs','views') ? json_encode(DB::table('blogs')->orderByDesc('views')->limit(6)->pluck('views','slug')) . ' total ' . DB::table('blogs')->sum('views') : 'n/a');
$q('blog_last', fn() => DB::table('blogs')->max('created_at'));
$q('feature_flags', fn() => json_encode(DB::table('feature_flags')->get()->map(fn($r) => (array)$r)->map(fn($r) => ($r['key'] ?? $r['name'] ?? '?') . '=' . ($r['enabled'] ?? $r['value'] ?? '?'))->values()));
$q('templates', fn() => DB::table('message_templates')->count());
$q('newsletter_sent', fn() => Schema::hasTable('notifications_log') ? DB::table('notifications_log')->where('created_at','>=',$d30)->count() : 'n/a');
$q('email_settings', fn() => json_encode(DB::table('email_settings')->pluck('key')));
foreach ($o as $k => $v) echo "@@ $k :: $v\n";
