<?php

use App\Jobs\ReevaluateHumanQueueJob;
use Illuminate\Support\Facades\Schedule;

// Periodically re-evaluate outstanding human questions for all active jobs:
// drop those answered elsewhere, refresh priorities, batch and re-ask.
Schedule::job(new ReevaluateHumanQueueJob)
    ->everyTenMinutes()
    ->name('research:reevaluate-human-queue')
    ->withoutOverlapping();
