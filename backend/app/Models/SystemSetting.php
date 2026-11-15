<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'array'];

    /** Setting value, falling back to config('certitrack.defaults'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);
        if ($row && $row->value !== null) {
            return $row->value['v'] ?? $default;
        }
        return config("certitrack.defaults.$key", $default);
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }

    public static function allSettings(): array
    {
        $out = [];
        foreach (array_keys(config('certitrack.defaults')) as $k) {
            $out[$k] = static::get($k);
        }
        return $out;
    }
}
