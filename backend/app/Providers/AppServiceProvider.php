<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by(strtolower((string) $r->input('email', $r->input('code', ''))) . '|' . $r->ip()));
        // Extension traffic: batches + heartbeats. Generous but bounded.
        RateLimiter::for('extension', fn (Request $r) => Limit::perMinute(240)->by(optional($r->user())->id ?: $r->ip()));
    }
}
