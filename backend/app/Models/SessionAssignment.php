<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionAssignment extends Model
{
    protected $fillable = ['student_id', 'instructor_id', 'platform_id'];

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function instructor() { return $this->belongsTo(User::class, 'instructor_id'); }
    public function platform() { return $this->belongsTo(CertificationPlatform::class, 'platform_id'); }
}
