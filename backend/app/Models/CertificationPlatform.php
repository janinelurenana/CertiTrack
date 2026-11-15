<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificationPlatform extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function domains()
    {
        return $this->hasMany(PlatformDomain::class, 'platform_id');
    }
}
