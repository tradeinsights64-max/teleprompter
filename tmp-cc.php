<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\CourseAccess;
use App\Support\CoursePricing;
use App\Support\CourseOrdering;
use App\Support\LearningPath;
use App\Http\Resources\CourseDetailResource;
use App\Http\Resources\CourseResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Category\Entities\Category;
use Modules\Course\App\Models\Course;
use Modules\Course\App\Models\CourseModuleLesson;
use Modules\Course\App\Models\Quiz;

/**
 * Public course catalogue.
 *
 * Mirrors the filtering behaviour of the Blade CourseController@index so the
 * React catalogue returns the same rows for the same inputs. Two deliberate
 * differences:
 *
 *  - Counts are SQL aggregates rather than the model's $appends accessors,
 *    which would issue several queries per row.
 *  - The published-status filter is applied here rather than relying on the
 *    relation-level filters baked into the models, because those same
 *    relations are reused by admin screens that must see draft content.
 */
class CourseController extends Controller
{
    public function __construct(private readonly CourseAccess $access)
    {
    }

    /**
     * Everything a course card needs, eager-loaded.
     *
     * The nested *.translate / *.front_translate entries are not decoration:
     * Category, CourseLevel and CourseLanguage all expose name as an $appends
     * accessor that reads front_translate and falls back to translate, so
     * without these each distinct instance costs two extra queries at
     * serialisation time.
     */
    protected const CARD_RELATIONS = [
        'category', 'category.translate', 'category.front_translate',
        'instructor',
        'course_level', 'course_level.translate', 'course_level.front_translate',
        'course_language', 'course_language.translate', 'course_language.front_translate',
        'translate', 'front_translate',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'category'   => ['nullable', 'string', 'max:255'],
            'search'     => ['nullable', 'string', 'max:255'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'sort'       => ['nullable', 'in:sequence,newest,oldest,price_low,price_high,rating'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:60'],
            // Which language path through the curriculum. Unrecognised
            // values fall back to the default rather than 422 — a stale
            // bookmark should show courses, not an error.
            'path'       => ['nullable', 'string', 'max:8'],
        ]);

        $query = Course::query()
            ->where('status', 'enable')
            ->where('approved_by_admin', 'approved')
            ->with(['category', 'instructor', 'course_level', 'course_language'])
            ->withCount(['enrolled_courses', 'reviews', 'modules'])
            ->withAvg('reviews', 'rating')
            ->addSelect(['lessons_count' => $this->lessonCountSubquery()]);

        /*
        | One path at a time.
        |
        | The thirteen sessions exist in both Amharic and English, so an
        | unfiltered catalogue is twenty-six rows with every session listed
        | twice. Applied unconditionally: with no path requested the default
        | is used, because "both" is not a coherent thing to show a student
        | following a numbered curriculum.
        */
        // No path asked for (the mobile app never sends one): a signed-in
        // student gets THEIR course language, so the app never lists the
        // other language's modules (2026-11-20, Molla).
        $path = $validated['path'] ?? null;
        if (! LearningPath::isValid($path)) {
            $viewer = $request->user() ?? auth('sanctum')->user();
            if ($viewer !== null) {
                $path = app(\App\Support\LearningTrack::class)->forStudent((int) $viewer->id) ?? $path;
            }
        }
        LearningPath::apply($query, $path);

        if (!empty($validated['category'])) {
            $category = Category::where('slug', $validated['category'])->first();
            // An unknown slug must yield an empty set, not the whole catalogue.
            $query->where('category_id', $category?->id ?? 0);
        }

        if (!empty($validated['search'])) {
            $term = $validated['search'];
            $query->whereHas('translate', fn ($q) => $q->where('title', 'like', "%{$term}%"));
        }

        // Price filters read offer_price when set and fall back to
        // regular_price, matching how the catalogue displays price.
        if (isset($validated['min_amount'])) {
            $min = $validated['min_amount'];
            $query->whereRaw('COALESCE(offer_price, regular_price, 0) >= ?', [$min]);
        }

        if (isset($validated['max_amount'])) {
            $max = $validated['max_amount'];
            $query->whereRaw('COALESCE(offer_price, regular_price, 0) <= ?', [$max]);
        }

        /*
         * Default is curriculum sequence, not "newest".
         *
         * The catalogue is an ordered course of study — session 1 through 13 —
         * so newest-first opened the listing on the last session and scattered
         * the sequence across pages. Every branch below ends in a deterministic
         * tie-break on id, without which rows with equal sort keys can swap
         * between pages and the same course appears twice while another is
         * never seen.
         */
        match ($validated['sort'] ?? 'sequence') {
            'newest'     => $query->latest('id'),
            'oldest'     => $query->oldest('id'),
            'price_low'  => $query->orderByRaw('COALESCE(offer_price, regular_price, 0) asc')->orderBy('courses.id'),
            'price_high' => $query->orderByRaw('COALESCE(offer_price, regular_price, 0) desc')->orderBy('courses.id'),
            'rating'     => $query->orderByDesc('reviews_avg_rating')->orderBy('courses.id'),
            default      => CourseOrdering::sequential($query),
        };

        // Nine per page: a 3x3 grid at desktop width, matching the listing's
        // three-column layout so no page ends with a ragged part-row.
        $courses = $query->paginate($validated['per_page'] ?? 9)->withQueryString();

        return CourseResource::collection($courses);
    }

    /**
     * Live count of enabled lessons across enabled modules.
     *
     * Written as an explicit subquery rather than withCount('modules'), because
     * the modules() relation carries an unqualified where('status', 'enable').
     * Joining course_module_lessons — which also has a status column — makes
     * that predicate ambiguous and MySQL rejects the query. Every column here
     * is table-qualified for the same reason.
     *
     * This is also why the manually-entered courses.total_lesson column is not
     * used: it drifts once lessons are added or removed after creation.
     */
    protected function lessonCountSubquery()
    {
        return CourseModuleLesson::query()
            ->selectRaw('count(*)')
            ->join('course_modules', 'course_modules.id', '=', 'course_module_lessons.course_module_id')
            ->whereColumn('course_modules.course_id', 'courses.id')
            ->where('course_modules.status', 'enable')
            ->where('course_module_lessons.status', 'enable');
    }

    /**
     * Course detail: everything the public programme page renders.
     *
     * The curriculum is eager-loaded rather than counted, because the page
     * lists it — so lesson count, total duration and the quiz count are all
     * derived from rows already in memory instead of costing a query each.
     * modules() and lessons() carry their own status = 'enable' filter and
     * serial ordering, so the constraint closure only has to order the modules.
     */
    public function show(Request $request, string $slug): CourseDetailResource
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('status', 'enable')
            ->where('approved_by_admin', 'approved')
            ->with(array_merge(self::CARD_RELATIONS, [
                // course_modules.serial is qualified because modules() carries
                // an unqualified where('status', ...) of its own.
                'modules' => fn ($q) => $q->orderBy('course_modules.serial'),
                'modules.lessons',
            ]))
            ->withCount(['enrolled_courses', 'reviews', 'modules'])
            ->withAvg('reviews', 'rating')
            ->firstOrFail();

        $lessons = $course->modules->pluck('lessons')->flatten();

        $course->lessons_count = $lessons->count();

        // Whole minutes. video_duration is a varchar column holding an integer
        // number of minutes, so it is cast before summing rather than trusting
        // PHP's string arithmetic.
        $course->duration_minutes = $lessons->sum(fn ($lesson) => (int) $lesson->video_duration);

        // One query for the whole course, not one per lesson.
        $course->quizzes_count = $lessons->isEmpty()
            ? 0
            : Quiz::whereIn('course_module_lesson_id', $lessons->pluck('id'))->count();

        /*
         | Who is asking.
         |
         | The page has three call-to-action states and used to render only
         | one: a guest link to /register. A signed-in student therefore saw
         | "Apply now" pointing at a redirect to /login, which for them is a
         | button that does nothing. The state is resolved here, once, rather
         | than left to the client to infer from a second request.
         */
        $viewer = $request->user();
        $enrolled = $viewer !== null && $this->access->isEnrolled((int) $course->id, $viewer);
        $free = CoursePricing::isFree($course);

        /*
         * Two gates, in order: the release clock, then the sequential rule
         * within the track. Computed here so the page can show WHY a module is
         * shut rather than only that it is — "finish Module 3" is actionable,
         * a grey button is not.
         */
        $gate = app(\App\Support\TrackProgression::class)->gate($course, $viewer?->id);

        $course->viewer_state = [
            'is_authenticated' => $viewer !== null,
            'is_enrolled' => $enrolled,
            'can_open' => $gate['allowed'],
            'blocked_reason' => $gate['reason'],
            'blocked_message' => $gate['message'],
            'blocked_by' => $gate['blocked_by'],
            // Only a free programme can be joined from this page; a priced one
            // goes through checkout, which the SPA does not own yet.
            'can_enrol' => $viewer !== null && ! $enrolled && $free,
            'continue_url' => $enrolled ? '/student/courses/' . $course->slug : null,
        ];

        return new CourseDetailResource($course, $this->relatedCourses($course));
    }

    /**
     * A few other programmes in the same category.
     *
     * Selected with the same aggregates as index() because the detail page
     * renders them through the same card component, and a card whose stats are
     * missing renders zeroes rather than failing loudly.
     */
    protected function relatedCourses(Course $course): Collection
    {
        return Course::query()
            ->where('status', 'enable')
            ->where('approved_by_admin', 'approved')
            ->where('category_id', $course->category_id)
            ->where('id', '!=', $course->id)
            ->with(self::CARD_RELATIONS)
            ->withCount(['enrolled_courses', 'reviews', 'modules'])
            ->withAvg('reviews', 'rating')
            ->addSelect(['lessons_count' => $this->lessonCountSubquery()])
            ->latest('id')
            ->take(3)
            ->get();
    }
}
