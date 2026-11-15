<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\PurgeOldData;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CertificationPlatform;
use App\Models\PlatformDomain;
use App\Models\SessionAssignment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit;
use App\Services\EventClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    // ---------- users ----------
    public function users(Request $request): JsonResponse
    {
        $q = User::query();
        if ($request->filled('role')) { $q->where('role', $request->query('role')); }
        if ($request->filled('q')) {
            $t = '%' . str_replace(['%', '_'], ['\%', '\_'], $request->query('q')) . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $t)->orWhere('email', 'like', $t));
        }
        return response()->json($q->orderBy('name')->paginate(min((int) $request->query('per_page', 20), 100)));
    }

    public function storeUser(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:128', 'role' => ['required', Rule::in(['student', 'instructor', 'admin'])],
            'student_number' => 'nullable|string|max:50',
        ]);
        $u = User::create($d + ['is_active' => true]);
        Audit::log($request, 'user.created', 'user', $u->id, ['role' => $u->role]);
        return response()->json(['data' => $u], 201);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $u = User::findOrFail($id);
        $d = $request->validate([
            'name' => 'sometimes|string|max:255', 'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($u->id)],
            'password' => 'sometimes|nullable|string|min:8|max:128', 'role' => ['sometimes', Rule::in(['student', 'instructor', 'admin'])],
            'is_active' => 'sometimes|boolean', 'student_number' => 'sometimes|nullable|string|max:50',
        ]);
        if ($u->id === $request->user()->id && ((isset($d['role']) && $d['role'] !== 'admin') || (isset($d['is_active']) && ! $d['is_active']))) {
            return response()->json(['message' => 'You cannot demote or deactivate your own account.'], 422);
        }
        if (empty($d['password'])) { unset($d['password']); }
        $u->update($d);
        if (isset($d['is_active']) && ! $d['is_active']) { $u->tokens()->delete(); }
        Audit::log($request, 'user.updated', 'user', $u->id, ['fields' => array_keys($d)]);
        return response()->json(['data' => $u->fresh()]);
    }

    public function deleteUser(Request $request, int $id): JsonResponse
    {
        $u = User::findOrFail($id);
        if ($u->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        if ($u->sessions()->exists()) {
            return response()->json(['message' => 'User has monitoring records. Deactivate instead, or purge records first.'], 409);
        }
        $u->tokens()->delete();
        $u->delete();
        Audit::log($request, 'user.deleted', 'user', $id);
        return response()->json(['message' => 'User deleted.']);
    }

    // ---------- platforms & domains ----------
    public function platforms(): JsonResponse
    {
        return response()->json(['data' => CertificationPlatform::with('domains')->orderBy('name')->get()]);
    }

    public function storePlatform(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => 'required|string|max:255', 'slug' => 'required|string|max:80|regex:/^[a-z0-9\-]+$/|unique:certification_platforms,slug',
            'description' => 'nullable|string|max:1000', 'is_active' => 'sometimes|boolean',
        ]);
        $p = CertificationPlatform::create($d + ['is_active' => true]);
        Audit::log($request, 'platform.created', 'platform', $p->id);
        return response()->json(['data' => $p->load('domains')], 201);
    }

    public function updatePlatform(Request $request, int $id): JsonResponse
    {
        $p = CertificationPlatform::findOrFail($id);
        $d = $request->validate([
            'name' => 'sometimes|string|max:255', 'description' => 'sometimes|nullable|string|max:1000', 'is_active' => 'sometimes|boolean',
        ]);
        $p->update($d);
        Audit::log($request, 'platform.updated', 'platform', $p->id, ['fields' => array_keys($d)]);
        return response()->json(['data' => $p->load('domains')]);
    }

    public function deletePlatform(Request $request, int $id): JsonResponse
    {
        $p = CertificationPlatform::findOrFail($id);
        if ($p->id && \App\Models\ExamSession::where('platform_id', $p->id)->exists()) {
            return response()->json(['message' => 'Platform has session records. Deactivate it instead.'], 409);
        }
        $p->delete();
        Audit::log($request, 'platform.deleted', 'platform', $id);
        return response()->json(['message' => 'Platform deleted.']);
    }

    public function storeDomain(Request $request, int $platformId): JsonResponse
    {
        $p = CertificationPlatform::findOrFail($platformId);
        $d = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/'],
            'match_type' => ['required', Rule::in(['suffix', 'exact'])],
            'path_prefix' => ['nullable', 'string', 'max:255', 'regex:/^\/[^?#\s]*$/'],
        ]);
        $d['host'] = strtolower($d['host']);
        if (PlatformDomain::where('platform_id', $p->id)->where('host', $d['host'])->where('path_prefix', $d['path_prefix'] ?? null)->exists()) {
            return response()->json(['message' => 'This domain rule already exists.'], 422);
        }
        $dom = PlatformDomain::create($d + ['platform_id' => $p->id, 'is_active' => true]);
        Audit::log($request, 'domain.created', 'platform_domain', $dom->id, ['host' => $dom->host, 'platform_id' => $p->id]);
        return response()->json(['data' => $dom], 201);
    }

    public function updateDomain(Request $request, int $id): JsonResponse
    {
        $dom = PlatformDomain::findOrFail($id);
        $d = $request->validate(['is_active' => 'required|boolean']);
        $dom->update($d);
        Audit::log($request, 'domain.updated', 'platform_domain', $dom->id, $d);
        return response()->json(['data' => $dom]);
    }

    public function deleteDomain(Request $request, int $id): JsonResponse
    {
        $dom = PlatformDomain::findOrFail($id);
        $dom->delete();
        Audit::log($request, 'domain.deleted', 'platform_domain', $id, ['host' => $dom->host]);
        return response()->json(['message' => 'Domain rule deleted.']);
    }

    // ---------- assignments ----------
    public function assignments(): JsonResponse
    {
        return response()->json(['data' => SessionAssignment::with(['student:id,name,email', 'instructor:id,name,email', 'platform:id,name'])->latest()->get()]);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $d = $request->validate([
            'student_id' => ['required', Rule::exists('users', 'id')->where('role', 'student')],
            'instructor_id' => ['required', Rule::exists('users', 'id')->where('role', 'instructor')],
            'platform_id' => 'nullable|exists:certification_platforms,id',
        ]);
        $d['platform_id'] = $d['platform_id'] ?? null;
        if (SessionAssignment::where($d)->exists()) {
            return response()->json(['message' => 'Assignment already exists.'], 422);
        }
        $a = SessionAssignment::create($d);
        Audit::log($request, 'assignment.created', 'session_assignment', $a->id, $d);
        return response()->json(['data' => $a->load(['student:id,name,email', 'instructor:id,name,email', 'platform:id,name'])], 201);
    }

    public function deleteAssignment(Request $request, int $id): JsonResponse
    {
        SessionAssignment::findOrFail($id)->delete();
        Audit::log($request, 'assignment.deleted', 'session_assignment', $id);
        return response()->json(['message' => 'Assignment removed.']);
    }

    // ---------- settings / audit / retention ----------
    public function settings(): JsonResponse
    {
        return response()->json(['data' => SystemSetting::allSettings(), 'meta' => ['event_types' => collect(config('certitrack.event_types'))->map(fn ($v, $k) => ['severity' => $v[0], 'source' => $v[1], 'label' => $v[2]])]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'heartbeat_interval_sec' => 'sometimes|integer|min:30|max:300',
            'heartbeat_timeout_sec' => 'sometimes|integer|min:60|max:1800',
            'disconnect_after_sec' => 'sometimes|integer|min:90|max:3600',
            'abandon_after_sec' => 'sometimes|integer|min:300|max:86400',
            'retention_days' => 'sometimes|integer|min:1|max:3650',
            'require_assignment' => 'sometimes|boolean',
            'enabled_events' => 'sometimes|array', 'enabled_events.*' => ['string', Rule::in(array_values(array_filter(EventClassifier::types(), [EventClassifier::class, 'isExtensionType'])))],
        ]);
        foreach ($d as $k => $v) { SystemSetting::put($k, $v); }
        Audit::log($request, 'settings.updated', 'settings', null, $d);
        return response()->json(['data' => SystemSetting::allSettings()]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $q = AuditLog::with('user:id,name,role');
        if ($request->filled('action')) { $q->where('action', 'like', $request->query('action') . '%'); }
        return response()->json($q->orderByDesc('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function purge(Request $request): JsonResponse
    {
        $v = $request->validate(['days' => 'nullable|integer|min:1|max:3650']);
        $days = (int) ($v['days'] ?? 0) ?: (int) SystemSetting::get('retention_days');
        $n = PurgeOldData::purge($days);
        return response()->json(['data' => ['deleted_sessions' => $n, 'days' => $days]]);
    }
}
