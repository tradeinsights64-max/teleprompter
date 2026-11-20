<?php
$d30 = now()->subDays(30); $o = [];
$q = function ($k, $f) use (&$o) { try { $o[$k] = $f(); } catch (\Throwable $e) { $o[$k] = 'ERR ' . substr($e->getMessage(), 0, 90); } };
$m = fn() => DB::table('members')->whereNull('deleted_at');
foreach (['all' => null, '30d' => $d30] as $lbl => $since) {
  $q("funnel_$lbl", function () use ($m, $since) {
    $b = fn() => $since ? $m()->where('created_at', '>=', $since) : $m();
    $r = ['started' => $b()->count()];
    foreach (['language_selected_at','socials_confirmed_at','registered_at','verified_at','first_deposit_at','joined_group_at'] as $c) $r[$c] = $b()->whereNotNull($c)->count();
    return json_encode($r);
  });
}
$q('started_by_bot_state', fn() => json_encode($m()->where('status','started')->select('bot_state', DB::raw('count(*) n'))->groupBy('bot_state')->orderByDesc('n')->limit(15)->pluck('n','bot_state')));
$q('started_30d_by_bot_state', fn() => json_encode($m()->where('status','started')->where('created_at','>=',$d30)->select('bot_state', DB::raw('count(*) n'))->groupBy('bot_state')->orderByDesc('n')->limit(15)->pluck('n','bot_state')));
$q('service_selected', fn() => json_encode($m()->select('service_selected', DB::raw('count(*) n'))->groupBy('service_selected')->orderByDesc('n')->limit(10)->pluck('n','service_selected')));
$q('language', fn() => json_encode($m()->select('language', DB::raw('count(*) n'))->groupBy('language')->pluck('n','language')));
$q('blocked_reason', fn() => json_encode($m()->whereNotNull('blocked_at')->select('blocked_reason', DB::raw('count(*) n'))->groupBy('blocked_reason')->orderByDesc('n')->limit(8)->pluck('n','blocked_reason')));
$q('events_top30d', fn() => json_encode(DB::table('events')->where('created_at','>=',$d30)->select('event_type', DB::raw('count(*) n'))->groupBy('event_type')->orderByDesc('n')->limit(30)->pluck('n','event_type')));
$q('tact_top30d', fn() => json_encode(DB::table('telegram_activities')->where('created_at','>=',$d30)->select('event_type', DB::raw('count(*) n'))->groupBy('event_type')->orderByDesc('n')->limit(20)->pluck('n','event_type')));
$q('tact_failed30d', fn() => json_encode(DB::table('telegram_activities')->where('created_at','>=',$d30)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n','status')));
$q('outbound_30d', fn() => DB::table('telegram_activities')->where('created_at','>=',$d30)->where('direction','out')->count() . ' / members30d ' . $m()->where('created_at','>=',$d30)->count());
$q('module_students', fn() => json_encode(DB::table('lesson_checklists')->select('course_id', DB::raw('count(distinct student_id) n'))->groupBy('course_id')->orderBy('course_id')->pluck('n','course_id')));
$q('module_students_30d', fn() => json_encode(DB::table('lesson_checklists')->where('created_at','>=',$d30)->select('course_id', DB::raw('count(distinct student_id) n'))->groupBy('course_id')->orderBy('course_id')->pluck('n','course_id')));
$q('learners_30d', fn() => DB::table('lesson_checklists')->where('created_at','>=',$d30)->distinct()->count('student_id'));
$q('learners_7d', fn() => DB::table('lesson_checklists')->where('created_at','>=',now()->subDays(7))->distinct()->count('student_id'));
$q('flags', fn() => json_encode(DB::table('feature_flags')->get()->map(fn($r) => collect((array)$r)->except(['id','created_at','updated_at','description'])->implode('|'))));
$q('follow_up_rules', fn() => json_encode(DB::table('follow_up_rules')->get()->map(fn($r) => collect((array)$r)->except(['created_at','updated_at'])->map(fn($v) => is_string($v) ? substr($v,0,40) : $v)->implode('|'))));
$q('orders_cols', fn() => implode(',', Schema::getColumnListing('orders')));
$q('order_types', fn() => json_encode(DB::table('orders')->select(DB::raw("concat(coalesce(order_type,''),'/',status) k"), DB::raw('count(*) n'))->groupBy('k')->pluck('n','k')));
$q('payments_status', fn() => json_encode(DB::table('payments')->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n','status')));
$q('tickets_by_cat', fn() => json_encode(DB::table('support_tickets')->select('subject')->latest()->limit(8)->pluck('subject')));
$q('bot_blocks_30d', fn() => DB::table('bot_block_events')->where('created_at','>=',$d30)->count());
foreach ($o as $k => $v) echo "@@ $k :: $v\n";
