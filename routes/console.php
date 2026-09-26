<?php

use Illuminate\Support\Facades\Schedule;

/*
 * The scheduler runs in its own container (`php artisan schedule:work`).
 * onOneServer() takes a cache lock, so each task runs once even if several
 * scheduler instances are deployed.
 */

Schedule::command('sla:detect-breaches')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('tickets:close-resolved')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

Schedule::command('queue:prune-failed --hours=168')->weekly()->onOneServer();
