<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CertificationPlatform;
use App\Models\ExamSession;
use App\Models\MonitoringEvent;
use App\Models\SessionAssignment;
use App\Models\SystemSetting;
use App\Services\Audit;
use App\Services\EventClassifier;
use App\Services\SessionLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SessionController extends Controller
{
    /** Session the current user may see, or 404 (never 403, so ids are not enumerable). */
    private function visible(Request $request, int $id): ExamSession
    {
        return ExamSession::visibleTo($request->user())->with(['platform:id,name,slug', 'student:id,name,email,student_number'])->findOrFail($id);
    }

    /** Session owned by the calling student (write operations). */
    private function owned(Request $request, int $id): ExamSession
    {
        return ExamSession::where('student_id', $request->user()->id)->findOrFail($id);
    }

    /** GET /api/sessions - the student's own history (filter: status, platform_id). */
    public function index(Request $request): JsonResponse
    {
        $q = ExamSession::where('student_id', $request->user()->id)->with('platform:id,name,slug');
        if ($request->filled('status')) { $q->where('status', $request->query('status')); }
        if ($request->filled('platform_id')) { $q->where('platform_id', (int) $request->query('platform_id')); }
        return response()->json($q->orderByDesc('started_at')->paginate(min((int) $request->query('per_page', 15), 100)));
    }

    /** GET /api/sessions/active */
    public function active(Request $request): JsonResponse
    {
        $s = ExamSession::where('student_id', $request->user()->id)->whereIn('status', ['active', 'interrupted'])
            ->with('platform:id,name,slug')->latest('started_at')->first();
        return response()->json(['data' => $s]);
    }

    /** POST /api/sessions - explicit, consented start. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform_id' => 'required|integer', 'consent' => 'required|accepted',
            'client_id' => 'nullable|string|max:64',
        ]);
        $user = $request->user();
        $platform = CertificationPlatform::where('is_active', true)->find($data['platform_id']);
        if (! $platform) {
            return response()->json(['message' => 'Platform not found or not active.'], 422);
        }
        if (SystemSetting::get('require_assignment')) {
            $ok = SessionAssignment::where('student_id', $user->id)
                ->where(fn ($w) => $w->whereNull('platform_id')->orWhere('platform_id', $platform->id))->exists();
            if (! $ok) {
                return response()->json(['message' => 'You are not assigned to an instructor for this platform.'], 403);
            }
        }
        $existing = ExamSession::where('student_id', $user->id)->whereIn('status', ['active', 'interrupted'])->first();
        if ($existing) {
            return response()->json(['message' => 'A monitoring session is already open.', 'data' => $existing->load('platform:id,name,slug')], 409);
        }
        $session = DB::transaction(function () use ($user, $platform, $data) {
            $s = ExamSession::create([
                'student_id' => $user->id, 'platform_id' => $platform->id, 'status' => 'active',
                'client_id' => $data['client_id'] ?? null, 'consent_version' => config('certitrack.consent_version'),
                'consented_at' => now(), 'started_at' => now(), 'last_heartbeat_at' => now(),
            ]);
            SessionLifecycle::serverEvent($s, 'session_started');
            return $s;
        });
        return response()->json(['data' => $session->load('platform:id,name,slug')], 201);
    }

    /** GET /api/sessions/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $s = $this->visible($request, $id);
        if ($request->user()->id !== $s->student_id) {
            Audit::log($request, 'session.viewed', 'exam_session', $s->id);
        }
        return response()->json(['data' => $s]);
    }

    /** GET /api/sessions/{id}/events (also used by instructor routes) */
    public function events(Request $request, int $id): JsonResponse
    {
        $s = $this->visible($request, $id);
        $q = MonitoringEvent::where('session_id', $s->id);
        foreach (['type', 'severity'] as $f) {
            if ($request->filled($f)) { $q->where($f, $request->query($f)); }
        }
        if ($request->boolean('flagged')) { $q->where('flagged', true); }
        $events = $q->orderBy('occurred_at')->orderBy('seq')->orderBy('id')->get();
        return response()->json(['data' => $events, 'meta' => [
            'note' => 'Events describe technical browser activity only. They are not proof of misconduct.',
        ]]);
    }

    /** POST /api/sessions/{id}/heartbeat */
    public function heartbeat(Request $request, int $id): JsonResponse
    {
        $s = $this->owned($request, $id);
        $data = $request->validate(['last_seq' => 'nullable|integer|min:0']);
        if (! $s->isOpen()) {
            return response()->json(['message' => 'Session is closed.', 'data' => ['status' => $s->status]], 409);
        }
        if (isset($data['last_seq'])) {
            $s->client_last_seq = max($s->client_last_seq, (int) $data['last_seq']);
        }
        SessionLifecycle::touch($s);
        $s->refresh();
        return response()->json(['data' => [
            'status' => $s->status, 'server_time' => now()->toIso8601String(),
            'heartbeat_interval_sec' => (int) SystemSetting::get('heartbeat_interval_sec'),
        ]]);
    }

    /** POST /api/sessions/{id}/end - idempotent. */
    public function end(Request $request, int $id): JsonResponse
    {
        $s = $this->owned($request, $id);
        $data = $request->validate(['last_seq' => 'nullable|integer|min:0']);
        $s = SessionLifecycle::end($s, $data['last_seq'] ?? null);
        return response()->json(['data' => $s->load('platform:id,name,slug')]);
    }

    /**
     * POST /api/sessions/{id}/events - batch ingestion.
     * Per-event results so one malformed event never blocks the rest of the queue.
     * Dedupe: event_uuid (global unique) and (session_id, seq).
     */
    public function storeEvents(Request $request, int $id): JsonResponse
    {
        $s = $this->owned($request, $id);
        $request->validate(['events' => 'required|array|min:1|max:100']);
        $enabled = SystemSetting::get('enabled_events', []);
        $now = now();
        $accepted = $duplicates = $rejected = [];

        $incomingUuids = collect($request->input('events'))->pluck('event_uuid')->filter(fn ($v) => is_string($v))->all();
        $known = MonitoringEvent::whereIn('event_uuid', $incomingUuids)->pluck('session_id', 'event_uuid');

        DB::transaction(function () use ($request, $s, $enabled, $now, $known, &$accepted, &$duplicates, &$rejected) {
            $added = 0; $score = 0; $maxSeq = $s->client_last_seq;
            foreach ($request->input('events') as $raw) {
                $uuid = is_array($raw) ? ($raw['event_uuid'] ?? null) : null;
                $v = Validator::make(is_array($raw) ? $raw : [], [
                    'event_uuid' => 'required|uuid', 'seq' => 'required|integer|min:1|max:4000000000',
                    'type' => 'required|string|max:64', 'client_timestamp' => 'required|date', 'details' => 'nullable|array',
                ]);
                if ($v->fails()) { $rejected[] = ['event_uuid' => $uuid, 'reason' => 'invalid']; continue; }
                $type = $raw['type'];
                if (! EventClassifier::isExtensionType($type)) { $rejected[] = ['event_uuid' => $uuid, 'reason' => 'unknown_type']; continue; }
                if (! in_array($type, $enabled, true)) { $rejected[] = ['event_uuid' => $uuid, 'reason' => 'disabled_type']; continue; }
                if (isset($known[$uuid])) {
                    if ((int) $known[$uuid] === $s->id) { $duplicates[] = $uuid; } else { $rejected[] = ['event_uuid' => $uuid, 'reason' => 'uuid_conflict']; }
                    continue;
                }
                $client = Carbon::parse($raw['client_timestamp'])->utc();
                // Clamp: an event cannot occur in the future or before the session began.
                $occurred = $client->greaterThan($now) ? $now->copy() : ($client->lessThan($s->started_at) ? $s->started_at->copy() : $client);
                $severity = EventClassifier::severity($type);
                try {
                    MonitoringEvent::create([
                        'session_id' => $s->id, 'event_uuid' => $uuid, 'seq' => (int) $raw['seq'], 'type' => $type,
                        'severity' => $severity, 'source' => 'extension', 'client_timestamp' => $client,
                        'received_at' => $now, 'occurred_at' => $occurred,
                        'details' => EventClassifier::sanitizeDetails($raw['details'] ?? null),
                        'received_after_end' => ! $s->isOpen(),
                    ]);
                } catch (QueryException $e) {
                    if ((string) $e->getCode() === '23000') { // unique violation: same uuid (race) or same seq
                        $dupe = MonitoringEvent::where('event_uuid', $uuid)->exists();
                        if ($dupe) { $duplicates[] = $uuid; } else { $rejected[] = ['event_uuid' => $uuid, 'reason' => 'seq_conflict']; }
                        continue;
                    }
                    throw $e;
                }
                $accepted[] = $uuid;
                $added++;
                $score += (int) config("certitrack.severity_weights.$severity", 0);
                $maxSeq = max($maxSeq, (int) $raw['seq']);
            }
            if ($added) {
                ExamSession::whereKey($s->id)->update([
                    'event_count' => DB::raw('event_count + ' . $added),
                    'review_score' => DB::raw('review_score + ' . $score),
                    'client_last_seq' => $maxSeq,
                ]);
            }
        });

        $s->refresh();
        SessionLifecycle::touch($s); // receiving events proves connectivity
        return response()->json(['data' => [
            'accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected,
            'session_status' => $s->fresh()->status, 'server_time' => now()->toIso8601String(),
        ]]);
    }
}
