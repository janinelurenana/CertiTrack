<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class Audit
{
    public static function log(?Request $request, string $action, ?string $subjectType = null, ?int $subjectId = null, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request?->user()?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata ?: null,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}
