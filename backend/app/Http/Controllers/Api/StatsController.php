<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSession;
use App\Models\MonitoringEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /** GET /api/stats - role-scoped real aggregates (no hardcoded numbers). */
    public function show(Request $request): JsonResponse
    {
        $u = $request->user();
        $sessions = fn () => ExamSession::visibleTo($u);

        $byStatus = $sessions()->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');
        $reviewPending = $sessions()->where('review_status', 'pending_review')->whereIn('status', ['completed', 'abandoned', 'interrupted'])->count();

        $ids = $sessions()->select('exam_sessions.id');
        $bySeverity = MonitoringEvent::whereIn('session_id', $ids)->select('severity', DB::raw('count(*) c'))->groupBy('severity')->pluck('c', 'severity');
        $byType = MonitoringEvent::whereIn('session_id', $ids)->select('type', DB::raw('count(*) c'))->groupBy('type')->orderByDesc('c')->limit(12)->pluck('c', 'type');

        $since = now()->subDays(13)->startOfDay();
        $perDayRaw = $sessions()->where('started_at', '>=', $since)
            ->select(DB::raw('DATE(started_at) d'), DB::raw('count(*) c'))->groupBy('d')->pluck('c', 'd');
        $perDay = [];
        for ($i = 0; $i < 14; $i++) {
            $d = $since->copy()->addDays($i)->toDateString();
            $perDay[$d] = (int) ($perDayRaw[$d] ?? 0);
        }

        $out = [
            'sessions_total' => $sessions()->count(),
            'sessions_by_status' => $byStatus,
            'pending_review' => $reviewPending,
            'events_by_severity' => $bySeverity,
            'events_by_type' => $byType,
            'sessions_per_day' => $perDay,
        ];
        if ($u->role === 'admin') {
            $out['users_by_role'] = User::select('role', DB::raw('count(*) c'))->groupBy('role')->pluck('c', 'role');
            $out['events_total'] = MonitoringEvent::count();
        }
        return response()->json(['data' => $out]);
    }
}
