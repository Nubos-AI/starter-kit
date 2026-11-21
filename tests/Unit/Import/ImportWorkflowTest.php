<?php

declare(strict_types=1);

use App\DTOs\Import\ImportData;
use App\Workflows\Import\ImportWorkflow;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->input = new ImportData('tenant', 'user', 'job', 'local', 'sheet.csv', 'csv', null, 500, 250);
});

afterEach(function (): void {
    WorkflowRun::end();
});

it('finishes successful import chunks before reporting completion', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new ImportWorkflow)->run($this->input));
    $run->resume()->resume()->resume();

    expect($run->isFinished())->toBeTrue()
        ->and(array_column($run->activities(), 'activity'))->toBe(['Import.processImportChunk', 'Import.processImportChunk', 'Import.finalizeImport'])
        ->and($run->activities()[2]['arguments'])->toBe(['tenant', 'user', 'job']);
});

it('reports a failed chunk and stops before later chunks then rethrows the activity failure', function (): void {
    $run = WorkflowRun::start(fn (): Generator => (new ImportWorkflow)->run($this->input));
    $exception = new RuntimeException('Activity retries exhausted');
    $run->fail($exception);

    expect(array_column($run->activities(), 'activity'))->toBe(['Import.processImportChunk', 'Import.finalizeImport'])
        ->and($run->activities()[1]['arguments'])->toBe(['tenant', 'user', 'job', true])
        ->and(fn () => $run->resume())->toThrow($exception);
});
