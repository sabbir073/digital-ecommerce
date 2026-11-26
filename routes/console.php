<?php

use Illuminate\Support\Facades\Schedule;

// One cPanel cron runs `schedule:run` every minute. It drains the queue so
// no always-on worker process is needed on shared hosting.
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// Unpaid orders are given up on once their stock hold has run out.
Schedule::command('orders:expire-pending')->everyFiveMinutes()->withoutOverlapping();
