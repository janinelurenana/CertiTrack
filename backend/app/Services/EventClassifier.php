<?php

namespace App\Services;

/**
 * Server-side event rules: severity is decided here, details are whitelisted.
 */
class EventClassifier
{
    public static function severity(string $type): ?string
    {
        return config("certitrack.event_types.$type.0");
    }

    public static function isExtensionType(string $type): bool
    {
        return config("certitrack.event_types.$type.1") === 'extension';
    }

    public static function types(): array
    {
        return array_keys(config('certitrack.event_types'));
    }

    /** Keep only whitelisted, well-formed detail values. Never stores URLs/queries. */
    public static function sanitizeDetails(?array $details): ?array
    {
        if (! $details) {
            return null;
        }
        $out = [];
        foreach (config('certitrack.allowed_detail_keys') as $key) {
            if (! array_key_exists($key, $details) || $details[$key] === null) {
                continue;
            }
            $v = $details[$key];
            switch ($key) {
                case 'host':
                    $v = is_string($v) ? strtolower(trim($v)) : '';
                    if (preg_match('/^[a-z0-9.\-]{1,253}$/', $v)) { $out[$key] = $v; }
                    break;
                case 'direction':
                    if (in_array($v, ['away', 'return'], true)) { $out[$key] = $v; }
                    break;
                case 'reason':
                    if (is_string($v) && preg_match('/^[a-z_]{1,64}$/', $v)) { $out[$key] = $v; }
                    break;
                case 'opener_is_exam':
                    $out[$key] = (bool) $v;
                    break;
                case 'queued_count':
                case 'gap_seconds':
                    if (is_numeric($v)) { $out[$key] = max(0, min(10_000_000, (int) $v)); }
                    break;
            }
        }
        return $out ?: null;
    }
}
