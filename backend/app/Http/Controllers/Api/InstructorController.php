<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSession;
use App\Models\MonitoringEvent;
use App\Models\SessionReview;
use App\Models\User;
use App\Services\Audit;
use App\Services\SessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InstructorController extends Controller
{
    private const REVIEW_STATUSES = ['pending_review', 'reviewed', 'no_concern', 'requires_investigation'];

    private function session(Request $request, int $id): ExamSession
    {
        return ExamSession::visibleTo($request->user())->with(['platform:id,name,slug', 'student:id,name,email,student_number'])->findOrFail($id);
    }

    /** GET /api/instructor/students - assigned students (all students for admins). */
    public function students(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = User::where('role', 'student')->where('is_active', true);
        if ($u->role === 'instructor') {
            $q->whereIn('id', DB::table('session_assignments')->where('instructor_id', $u->id)->select('student_id'));
        }
        return response()->json(['data' => $q->orderBy('name')->get(['id', 'name', 'email', 'student_number'])]);
    }

    /** GET /api/instructor/sessions - filter: student_id, platform_id, status, review_status, from, to, q; sort; paginate. */
    public function sessions(Request $request): JsonResponse
    {
        SessionLifecycle::sweepThrottled();
        $request->validate([
            'status' => ['nullable', Rule::in(['scheduled', 'active', 'interrupted', 'completed', 'abandoned'])],
            'review_status' => ['nullable', Rule::in(self::REVIEW_STATUSES)],
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'sort' => ['nullable', Rule::in(['started_at', 'ended_at', 'event_count', 'review_score', 'status', 'student'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $q = ExamSession::visibleTo($request->user())->with(['platform:id,name,slug', 'student:id,name,email,student_number']);
        foreach (['status', 'review_status'] as $f) {
            if ($request->filled($f)) { $q->where("exam_sessions.$f", $request->query($f)); }
        }
        if ($request->filled('student_id')) { $q->where('exam_sessions.student_id', (int) $request->query('student_id')); }
        if ($request->filled('platform_id')) { $q->where('exam_sessions.platform_id', (int) $request->query('platform_id')); }
        if ($request->filled('from')) { $q->where('exam_sessions.started_at', '>=', $request->date('from')->startOfDay()->utc()); }
        if ($request->filled('to')) { $q->where('exam_sessions.started_at', '<=', $request->date('to')->endOfDay()->utc()); }
        if ($request->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $request->query('q')) . '%';
            $q->whereIn('exam_sessions.student_id', User::where('name', 'like', $term)->orWhere('email', 'like', $term)->select('id'));
        }
        $sort = $request->query('sort', 'started_at');
        $dir = $request->query('dir', 'desc');
        if ($sort === 'student') {
            $q->orderBy(User::select('name')->whereColumn('users.id', 'exam_sessions.student_id'), $dir);
        } else {
            $q->orderBy("exam_sessions.$sort", $dir);
        }
        return response()->json($q->paginate(min((int) $request->query('per_page', 15), 100)));
    }

    /** GET /api/instructor/sessions/{id}/events - audited access. */
    public function events(Request $request, int $id): JsonResponse
    {
        $s = $this->session($request, $id);
        Audit::log($request, 'session.events_viewed', 'exam_session', $s->id, ['student_id' => $s->student_id]);
        $q = MonitoringEvent::where('session_id', $s->id);
        foreach (['type', 'severity'] as $f) {
            if ($request->filled($f)) { $q->where($f, $request->query($f)); }
        }
        $events = $q->orderBy('occurred_at')->orderBy('seq')->orderBy('id')->get();
        return response()->json(['data' => $events, 'meta' => [
            'session' => $s,
            'reviews' => SessionReview::where('session_id', $s->id)->with('reviewer:id,name')->latest()->get(),
            'note' => 'Events describe technical browser activity only. They are not proof of misconduct.',
        ]]);
    }

    /** POST /api/instructor/sessions/{id}/review */
    public function review(Request $request, int $id): JsonResponse
    {
        $s = $this->session($request, $id);
        $data = $request->validate([
            'status' => ['required', Rule::in(self::REVIEW_STATUSES)],
            'remarks' => 'nullable|string|max:2000',
            'event_id' => 'nullable|integer',
        ]);
        $event = null;
        if (! empty($data['event_id'])) {
            $event = MonitoringEvent::where('session_id', $s->id)->find($data['event_id']);
            if (! $event) {
                return response()->json(['message' => 'Event does not belong to this session.'], 422);
            }
        }
        $review = DB::transaction(function () use ($s, $data, $event, $request) {
            $r = SessionReview::create([
                'session_id' => $s->id, 'event_id' => $event?->id, 'reviewer_id' => $request->user()->id,
                'status' => $data['status'], 'remarks' => $data['remarks'] ?? null,
            ]);
            if ($event) { $event->update(['flagged' => true]); }
            $s->update(['review_status' => $data['status']]);
            return $r;
        });
        Audit::log($request, 'session.reviewed', 'exam_session', $s->id, ['status' => $data['status'], 'event_id' => $event?->id]);
        return response()->json(['data' => $review->load('reviewer:id,name')], 201);
    }

    /** DELETE /api/instructor/sessions/{id}/events/{eventId}/flag */
    public function unflag(Request $request, int $id, int $eventId): JsonResponse
    {
        $s = $this->session($request, $id);
        $e = MonitoringEvent::where('session_id', $s->id)->findOrFail($eventId);
        $e->update(['flagged' => false]);
        Audit::log($request, 'event.unflagged', 'monitoring_event', $e->id);
        return response()->json(['message' => 'Flag removed.']);
    }

    /** GET /api/instructor/sessions/{id}/report - JSON report (rendered by the printable page). */
    public function report(Request $request, int $id): JsonResponse
    {
        $s = $this->session($request, $id);
        $events = MonitoringEvent::where('session_id', $s->id)->orderBy('occurred_at')->orderBy('seq')->orderBy('id')->get();
        Audit::log($request, 'report.generated', 'exam_session', $s->id);
        return response()->json(['data' => [
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $request->user()->only(['id', 'name']),
            'session' => $s,
            'summary' => [
                'by_severity' => $events->groupBy('severity')->map->count(),
                'by_type' => $events->groupBy('type')->map->count(),
                'flagged' => $events->where('flagged', true)->count(),
                'late_events' => $events->where('received_after_end', true)->count(),
            ],
            'events' => $events,
            'reviews' => SessionReview::where('session_id', $s->id)->with('reviewer:id,name')->orderBy('created_at')->get(),
            'disclaimer' => 'Events describe technical browser activity only (e.g. tab switches, focus changes). They can have legitimate explanations and are not proof of misconduct. Client-reported events can be incomplete or manipulated.',
        ]]);
    }
}
