<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ExamSession;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

class PurgeOldData extends Command
{
    protected $signature = 'certitrack:purge {--days= : Override retention_days setting}';
    protected $description = 'Delete closed sessions (and their events/reviews) older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: SystemSetting::get('retention_days'));
        $n = self::purge($days);
        $this->info("Deleted {$n} session(s) older than {$days} days.");
        return self::SUCCESS;
    }

    public static function purge(int $days): int
    {
        $cutoff = now()->subDays(max(1, $days));
        $n = ExamSession::whereIn('status', ['completed', 'abandoned'])->where('ended_at', '<', $cutoff)->delete();
        AuditLog::create(['action' => 'retention.purge', 'metadata' => ['days' => $days, 'sessions_deleted' => $n], 'created_at' => now()]);
        return $n;
    }
}
