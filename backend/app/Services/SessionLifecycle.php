<?php

namespace App\Services;

use App\Models\ExamSession;
use App\Models\MonitoringEvent;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Session state transitions. Server time is authoritative everywhere.
 */
class SessionLifecycle
{
    /** Insert a server-generated event (no client sequence number). */
    public static function serverEvent(ExamSession $s, string $type, ?array $details = null, ?Carbon $at = null): MonitoringEvent
    {
        $at = $at ?? now();
        $severity = EventClassifier::severity($type) ?? 'info';
        $e = MonitoringEvent::create([
            'session_id' => $s->id, 'event_uuid' => (string) Str::uuid(), 'seq' => null,
            'type' => $type, 'severity' => $severity, 'source' => 'server',
            'client_timestamp' => null, 'received_at' => $at, 'occurred_at' => $at,
            'details' => $details, 'received_after_end' => ! $s->isOpen(),
        ]);
        $w = (int) config("certitrack.severity_weights.$severity", 0);
        ExamSession::whereKey($s->id)->update(['review_score' => \Illuminate\Support\Facades\DB::raw('review_score + ' . $w)]);
        return $e;
    }

    /** Called when a heartbeat or event batch proves the extension is alive. */
    public static function touch(ExamSession $s): void
    {
        if (! $s->isOpen()) {
            return;
        }
        $wasInterrupted = $s->status === 'interrupted';
        $gap = $wasInterrupted && $s->interrupted_at ? (int) $s->interrupted_at->diffInSeconds(now(), true) : null;
        $s->last_heartbeat_at = now();
        if ($wasInterrupted) {
            $s->status = 'active';
            $s->interrupted_at = null;
        }
        $s->save();
        if ($wasInterrupted) {
            self::serverEvent($s, 'monitoring_resumed', ['gap_seconds' => $gap]);
        }
    }

    public static function end(ExamSession $s, ?int $clientLastSeq): ExamSession
    {
        if (! $s->isOpen()) {
            return $s;
        }
        $s->status = 'completed';
        $s->ended_at = now();
        $s->interrupted_at = null;
        if ($clientLastSeq !== null) {
            $s->client_last_seq = max($s->client_last_seq, $clientLastSeq);
        }
        $s->save();
        self::serverEvent($s->refresh(), 'session_ended');
        return $s->refresh();
    }

    /**
     * Heartbeat timeout handling. Safe to call repeatedly (idempotent per threshold).
     *  - no heartbeat > timeout          -> interrupted + heartbeat_timeout (medium)
     *  - still silent > disconnect_after -> extension_disconnected (high), once
     *  - silent > abandon_after          -> abandoned
     * Returns number of sessions changed.
     */
    public static function sweep(): int
    {
        $timeout = (int) SystemSetting::get('heartbeat_timeout_sec');
        $disconnect = (int) SystemSetting::get('disconnect_after_sec');
        $abandon = (int) SystemSetting::get('abandon_after_sec');
        $changed = 0;

        ExamSession::whereIn('status', ['active', 'interrupted'])
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subSeconds($timeout))
            ->get()
            ->each(function (ExamSession $s) use ($disconnect, $abandon, &$changed) {
                $silent = (int) $s->last_heartbeat_at->diffInSeconds(now(), true);

                if ($s->status === 'active') {
                    $s->status = 'interrupted';
                    $s->interrupted_at = $s->last_heartbeat_at;
                    $s->save();
                    self::serverEvent($s, 'heartbeat_timeout', ['gap_seconds' => $silent]);
                    $changed++;
                }
                if ($silent >= $disconnect && ! $s->events()->where('type', 'extension_disconnected')->where('occurred_at', '>=', $s->interrupted_at ?? $s->started_at)->exists()) {
                    self::serverEvent($s, 'extension_disconnected', ['gap_seconds' => $silent]);
                    $changed++;
                }
                if ($silent >= $abandon) {
                    $s->status = 'abandoned';
                    $s->ended_at = now();
                    $s->save();
                    self::serverEvent($s->refresh(), 'session_abandoned', ['gap_seconds' => $silent]);
                    $changed++;
                }
            });

        return $changed;
    }

    /** Lazy sweep so interruptions show up even without the scheduler running (max once / 20 s). */
    public static function sweepThrottled(): void
    {
        if (Cache::add('certitrack:sweep-lock', 1, 20)) {
            self::sweep();
        }
    }
}
