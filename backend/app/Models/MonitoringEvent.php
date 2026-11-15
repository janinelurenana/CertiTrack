<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'details' => 'array', 'flagged' => 'boolean', 'received_after_end' => 'boolean',
        'client_timestamp' => 'datetime', 'received_at' => 'datetime', 'occurred_at' => 'datetime',
    ];

    protected $appends = ['label'];

    public function session() { return $this->belongsTo(ExamSession::class, 'session_id'); }

    public function getLabelAttribute(): string
    {
        return config("certitrack.event_types.{$this->type}.2", $this->type);
    }
}
