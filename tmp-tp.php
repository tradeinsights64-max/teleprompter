<?php

namespace App\Support;

use Modules\Course\App\Models\Course;

/**
 * Sequential progression within a track.
 *
 * The academy runs two independent tracks — English and Amharic — each of
 * thirteen modules. A student may take either or both, and within a track the
 * modules must be taken in order: module N opens only once module N-1 is
 * finished.
 *
 * THE TRACKS DO NOT TALK TO EACH OTHER. Finishing English module 3 says
 * nothing about Amharic module 4. That is why the previous module is looked up
 * by BOTH track and number rather than by "the course with the next id" — the
 * two tracks are interleaved in the id sequence, so an id-ordered rule would
 * unlock the wrong language.
 *
 * Courses outside the tracks — a one-off programme, a test course — have no
 * predecessor and are never gated.
 */
class TrackProgression
{
    public function __construct(private readonly StudentProgress $progress) {}

    /**
     * The course a student must finish before this one, or null when there is
     * no prerequisite.
     */
    public function previousModule(Course $course): ?Course
    {
        $parsed = CourseRelease::parse($course->slug);

        if ($parsed === null || $parsed['module'] <= 1) {
            return null;
        }

        return self::moduleInTrack($parsed['track'], $parsed['module'] - 1);
    }

    /**
     * Find one module of one track.
     *
     * Matched on the slug because that is where the track and number live.
     * English slugs end `-en`; Amharic ones do not, so the Amharic lookup has
     * to exclude the English row explicitly or `session-4-%` would match both.
     */
    public static function moduleInTrack(string $track, int $module): ?Course
    {
        $query = Course::query()
            ->where('status', 'enable')
            ->where('slug', 'like', "session-{$module}-%");

        if ($track === 'en') {
            $query->where('slug', 'like', '%-en');
        } else {
            $query->where('slug', 'not like', '%-en');
        }

        return $query->first();
    }

    /**
     * May this student open this course yet?
     *
     * @return array{allowed:bool, reason:?string, blocked_by:?array{slug:string,title:string,module:int}}
     */
    public function gate(Course $course, ?int $studentId): array
    {
        // 1. The clock. Applies to everyone, enrolled or not.
        if (! CourseRelease::isReleased($course)) {
            $payload = CourseRelease::payload($course);

            return [
                'allowed' => false,
                'reason' => 'not_released',
                'message' => 'This module will be live on ' . ($payload['available_from_label'] ?? 'a later date') . '.',
                'blocked_by' => null,
            ];
        }

        if ($studentId === null) {
            return ['allowed' => true, 'reason' => null, 'message' => null, 'blocked_by' => null];
        }

        // 1b. One language per student (2026-11-20, Molla): a student who is
        //     learning in Amharic cannot open English modules, and the other
        //     way round. They change language with the English/አማርኛ switch.
        $mine = app(LearningTrack::class)->forStudent($studentId);
        $theirs = LearningTrack::ofSlug($course->slug);
        if ($mine !== null && $theirs !== null && $mine !== $theirs) {
            return [
                'allowed' => false,
                'reason' => 'other_language',
                'message' => $mine === 'am'
                    ? 'You are learning in Amharic. Continue with the Amharic modules. · ትምህርቱን በአማርኛ እየተማሩ ነው፤ በአማርኛ ሞጁሎች ይቀጥሉ።'
                    : 'You are learning in English. Continue with the English modules. · ትምህርቱን በእንግሊዝኛ እየተማሩ ነው፤ በእንግሊዝኛ ሞጁሎች ይቀጥሉ።',
                'blocked_by' => null,
            ];
        }

        // 2. The one before it, in the SAME track.
        $previous = $this->previousModule($course);

        if ($previous === null) {
            return ['allowed' => true, 'reason' => null, 'message' => null, 'blocked_by' => null];
        }

        $totals = $this->progress->courseTotals((int) $previous->id, $studentId);

        if ($totals['is_complete']) {
            return ['allowed' => true, 'reason' => null, 'message' => null, 'blocked_by' => null];
        }

        $parsed = CourseRelease::parse($previous->slug);

        return [
            'allowed' => false,
            'reason' => 'previous_module_incomplete',
            'message' => 'Finish Module ' . ($parsed['module'] ?? '?') . ' before starting this one.',
            'blocked_by' => [
                'slug' => $previous->slug,
                'title' => html_decode((string) $previous->title),
                'module' => $parsed['module'] ?? 0,
                'percentage' => $totals['percentage'],
            ],
        ];
    }
}
