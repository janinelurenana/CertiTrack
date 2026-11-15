<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDomain extends Model
{
    protected $fillable = ['platform_id', 'host', 'match_type', 'path_prefix', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function platform()
    {
        return $this->belongsTo(CertificationPlatform::class, 'platform_id');
    }
}
