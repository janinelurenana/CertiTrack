<?php

namespace App\Console\Commands;

use App\Services\SessionLifecycle;
use Illuminate\Console\Command;

class SweepSessions extends Command
{
    protected $signature = 'certitrack:sweep';
    protected $description = 'Mark sessions with missing heartbeats as interrupted / abandoned';

    public function handle(): int
    {
        $n = SessionLifecycle::sweep();
        $this->info("Sweep complete, {$n} change(s).");
        return self::SUCCESS;
    }
}
