<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lrs:mailbox-tick')->everyMinute()->withoutOverlapping();

Schedule::command('lrs:followup-tick')->everyMinute()->withoutOverlapping(5);

Schedule::command('lifecycle:maintain')->everyMinute()->withoutOverlapping(5);

Schedule::command('lrs:operations-heartbeat')->everyMinute()->withoutOverlapping(2);
