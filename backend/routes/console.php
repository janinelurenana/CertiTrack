<?php

use Illuminate\Support\Facades\Schedule;

// Requires `php artisan schedule:work` (dev) or the standard cron entry (prod).
Schedule::command('certitrack:sweep')->everyMinute();
Schedule::command('certitrack:purge')->daily();
