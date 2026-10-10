<?php

declare(strict_types=1);

use App\Support\Import\ImportErrorReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Cache::flush();

    $this->importJobId = ModelStub::ulid('import-job');
    $this->tenantId = ModelStub::ulid('tenant');

    /** @var callable():string */
    $this->persisted = function (): string {
        $path = ImportErrorReport::persist($this->importJobId, $this->tenantId, 'local');

        return (string) Storage::disk('local')->get((string) $path);
    };
});

it('keeps no error report for a run without a single failing row', function (): void {
    expect(ImportErrorReport::errors($this->importJobId))->toBe([])
        ->and(ImportErrorReport::persist($this->importJobId, $this->tenantId, 'local'))->toBeNull();
});

it('collects a failing row with its number, its record identity and the reason', function (): void {
    ImportErrorReport::add($this->importJobId, 7, 'Pflichtfeld fehlt.', 'EXT-7');

    expect(ImportErrorReport::errors($this->importJobId))
        ->toBe([['row' => 7, 'reason' => 'Pflichtfeld fehlt.', 'identity' => 'EXT-7']]);
});

it('heads the error report with german column titles', function (): void {
    ImportErrorReport::add($this->importJobId, 2, 'Fehler', 'EXT-2');

    expect(($this->persisted)())->toStartWith(
        __('i18n.backend.support.import.import_error_report.row').','
        .__('i18n.backend.support.import.import_error_report.record').','
        .__('i18n.backend.support.import.import_error_report.reason'),
    );
});

it('sorts the error report by row number no matter in which order the rows failed', function (): void {
    ImportErrorReport::add($this->importJobId, 9, 'Neun', 'EXT-9');
    ImportErrorReport::add($this->importJobId, 3, 'Drei', 'EXT-3');

    $lines = array_values(array_filter(explode("\n", ($this->persisted)())));

    expect($lines[1])->toStartWith('3,')
        ->and($lines[2])->toStartWith('9,');
});

it('defuses a formula in the identity and in the reason of the error report', function (): void {
    ImportErrorReport::add($this->importJobId, 1, '=CMD()', '+EXT');

    $report = ($this->persisted)();

    expect($report)->toContain("'+EXT")
        ->and($report)->toContain("'=CMD()");
});

it('stores the error report inside the folder of its tenant', function (): void {
    ImportErrorReport::add($this->importJobId, 1, 'Fehler', 'EXT-1');

    expect(ImportErrorReport::persist($this->importJobId, $this->tenantId, 'local'))
        ->toBe("imports/{$this->tenantId}/errors/{$this->importJobId}.csv");
});

it('forgets the collected rows once the report is written so a rerun starts clean', function (): void {
    ImportErrorReport::add($this->importJobId, 1, 'Fehler', 'EXT-1');
    ImportErrorReport::persist($this->importJobId, $this->tenantId, 'local');

    expect(ImportErrorReport::errors($this->importJobId))->toBe([]);
});

it('keeps the error rows of two runs apart', function (): void {
    $otherJobId = ModelStub::ulid('other-import-job');

    ImportErrorReport::add($this->importJobId, 1, 'Eigen', 'EXT-1');
    ImportErrorReport::add($otherJobId, 1, 'Fremd', 'EXT-2');

    expect(ImportErrorReport::errors($this->importJobId))
        ->toBe([['row' => 1, 'reason' => 'Eigen', 'identity' => 'EXT-1']]);
});
