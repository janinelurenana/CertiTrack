<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CertificationPlatform;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

class PlatformController extends Controller
{
    private function active()
    {
        return CertificationPlatform::where('is_active', true)
            ->with(['domains' => fn ($q) => $q->where('is_active', true)->select('id', 'platform_id', 'host', 'match_type', 'path_prefix')])
            ->orderBy('name')->get(['id', 'name', 'slug', 'description']);
    }

    /** GET /api/platforms */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->active()]);
    }

    /** GET /api/platforms/config - what the generic extension engine needs, all admin-configurable. */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'platforms' => $this->active(),
            'settings' => [
                'heartbeat_interval_sec' => (int) SystemSetting::get('heartbeat_interval_sec'),
                'heartbeat_timeout_sec' => (int) SystemSetting::get('heartbeat_timeout_sec'),
                'enabled_events' => SystemSetting::get('enabled_events'),
                'consent_version' => config('certitrack.consent_version'),
            ],
            'server_time' => now()->toIso8601String(),
        ]]);
    }
}
