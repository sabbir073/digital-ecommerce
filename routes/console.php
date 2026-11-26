<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

// One cPanel cron runs `schedule:run` every minute. It drains the queue so
// no always-on worker process is needed on shared hosting.
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// Unpaid orders are given up on once their stock hold has run out.
Schedule::command('orders:expire-pending')->everyFiveMinutes()->withoutOverlapping();

// Review-request emails go out a set number of days after an order is paid (Admin > Reviews > Settings).
Schedule::command('reviews:send-requests')->hourly()->withoutOverlapping();

// A database backup every night. Admin > Backups lists them and sets how many are kept.
Schedule::command('backup:run')->dailyAt('03:10')->withoutOverlapping();

// A heartbeat, so `php artisan store:check` can tell whether the cron job is running.
Schedule::call(fn () => Setting::put('system.cron_at', now()->toIso8601String()))->everyFiveMinutes()->name('cron-heartbeat');
