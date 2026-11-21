<?php

declare(strict_types=1);

use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Support\Reports\ReportInputRules;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('binds every reason a tile cannot run for to the field the editor renders', function (ReportNotExecutableReason $reason, array $fields): void {
    $exception = ReportInputRules::toValidationException(new ReportNotExecutableException($reason), []);

    expect(array_keys($exception->errors()))->toBe($fields);
})->with([
    'missing app tile' => [ReportNotExecutableReason::TileSourceMissing, ['source_key']],
    'missing report' => [ReportNotExecutableReason::ReportMissing, ['report_id']],
    'missing goal' => [ReportNotExecutableReason::GoalMissing, ['goal_id']],
]);
