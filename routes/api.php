<?php

declare(strict_types=1);

use App\Enums\Api\ApiAccessLevel;
use App\Http\Controllers\Api\V1\Goals\GoalsController;
use App\Http\Controllers\Api\V1\Records\RecordsController;
use App\Http\Controllers\Api\V1\Reports\ReportsController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\WhoamiController;
use App\Http\Middleware\Api\EnsureRecordAbility;
use App\Http\Middleware\Api\EnsureReportAbility;
use App\Support\Engine\RecordRouteResolver;
use Illuminate\Support\Facades\Route;

$read = EnsureRecordAbility::class.':'.ApiAccessLevel::Read->value;
$write = EnsureRecordAbility::class.':'.ApiAccessLevel::Write->value;
$reportAbility = EnsureReportAbility::class;

Route::prefix('v1')->group(function () use ($read, $write, $reportAbility): void {
    Route::get('whoami', WhoamiController::class);

    Route::get('search', SearchController::class)
        ->middleware($read);

    Route::get('reports', [ReportsController::class, 'index'])
        ->middleware($read);

    Route::get('reports/{report}', [ReportsController::class, 'show'])
        ->middleware([$read, $reportAbility]);

    Route::get('reports/{report}/result', [ReportsController::class, 'result'])
        ->middleware([$read, $reportAbility]);

    Route::get('goals', [GoalsController::class, 'index'])
        ->middleware($read);

    Route::get('goals/{goal}', [GoalsController::class, 'show'])
        ->middleware([$read, $reportAbility]);

    Route::get('{typeSlug}', [RecordsController::class, 'index'])
        ->middleware($read);

    Route::get('{typeSlug}/{record}', [RecordsController::class, 'show'])
        ->where('record', RecordRouteResolver::routePattern())
        ->middleware($read);

    Route::post('{typeSlug}', [RecordsController::class, 'store'])
        ->middleware($write);

    Route::patch('{typeSlug}/{record}', [RecordsController::class, 'update'])
        ->where('record', RecordRouteResolver::routePattern())
        ->middleware($write);

    Route::delete('{typeSlug}/{record}', [RecordsController::class, 'destroy'])
        ->where('record', RecordRouteResolver::routePattern())
        ->middleware($write);
});
