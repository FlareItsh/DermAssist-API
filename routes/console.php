<?php

use App\Console\Commands\ProcessScheduledAccountActions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    ProcessScheduledAccountActions::processDueActions();
})->everyMinute();
