<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionReview extends Model
{
    protected $fillable = ['session_id', 'event_id', 'reviewer_id', 'status', 'remarks'];

    public function reviewer() { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function session() { return $this->belongsTo(ExamSession::class, 'session_id'); }
}
