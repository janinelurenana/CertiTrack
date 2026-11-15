<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExamSession extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'consented_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime',
        'last_heartbeat_at' => 'datetime', 'interrupted_at' => 'datetime',
    ];

    protected $appends = ['duration_seconds', 'possibly_missing_events'];

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function platform() { return $this->belongsTo(CertificationPlatform::class, 'platform_id'); }
    public function events() { return $this->hasMany(MonitoringEvent::class, 'session_id'); }
    public function reviews() { return $this->hasMany(SessionReview::class, 'session_id'); }

    public function getDurationSecondsAttribute(): int
    {
        $end = $this->ended_at ?? now();
        return max(0, (int) $this->started_at->diffInSeconds($end, true));
    }

    /** Integrity hint: events the extension says it produced but the server never received. */
    public function getPossiblyMissingEventsAttribute(): int
    {
        $received = $this->events()->where('source', 'extension')->count();
        return max(0, $this->client_last_seq - $received);
    }

    /** Role-based visibility. Students: own. Instructors: assigned students. Admin: all. */
    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        if ($u->role === 'admin') {
            return $q;
        }
        if ($u->role === 'student') {
            return $q->where('exam_sessions.student_id', $u->id);
        }
        if ($u->role === 'instructor') {
            return $q->whereExists(function ($sub) use ($u) {
                $sub->selectRaw('1')->from('session_assignments as sa')
                    ->whereColumn('sa.student_id', 'exam_sessions.student_id')
                    ->where('sa.instructor_id', $u->id)
                    ->where(function ($w) {
                        $w->whereNull('sa.platform_id')->orWhereColumn('sa.platform_id', 'exam_sessions.platform_id');
                    });
            });
        }
        return $q->whereRaw('1 = 0');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['active', 'interrupted'], true);
    }
}
