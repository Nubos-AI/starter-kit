<?php

declare(strict_types=1);

use App\Console\Commands\PruneAuditEntries;
use App\Console\Commands\PruneIdempotencyKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PruneAuditEntries::class)->monthlyOn(1, '03:00');
Schedule::command(PruneIdempotencyKeys::class)->hourly()->withoutOverlapping();
