<?php

declare(strict_types=1);

use App\Support\Export\CsvExportWriter;
use App\Support\Export\ExportCellSanitizer;
use App\Support\Export\JsonExportWriter;
use App\Support\Export\XlsxExportWriter;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    $this->sanitizer = new ExportCellSanitizer;
    $this->csvWriter = new CsvExportWriter($this->sanitizer);
    $this->xlsxWriter = new XlsxExportWriter($this->sanitizer);
    $this->jsonWriter = new JsonExportWriter;

    /** @var callable(list<string>, list<array<string, mixed>>, list<string>):string */
    $this->csv = function (array $headers, array $rows, array $numericHeaders = []): string {
        $this->csvWriter->write('local', 'exports/probe.csv', $headers, $rows, $numericHeaders);

        return (string) Storage::disk('local')->get('exports/probe.csv');
    };

    /** @var callable(list<string>, list<array<string, mixed>>, list<string>):array<string, array{value: mixed, type: string}> */
    $this->xlsx = function (array $headers, array $rows, array $numericHeaders = []): array {
        $this->xlsxWriter->write('local', 'exports/probe.xlsx', $headers, $rows, $numericHeaders);

        $temporary = tempnam(sys_get_temp_dir(), 'probe_xlsx_');
        file_put_contents((string) $temporary, (string) Storage::disk('local')->get('exports/probe.xlsx'));

        $sheet = IOFactory::load((string) $temporary)->getActiveSheet();

        $cells = [];

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cells[$cell->getCoordinate()] = [
                    'value' => $cell->getValue(),
                    'type' => $cell->getDataType(),
                ];
            }
        }

        unlink((string) $temporary);

        return $cells;
    };
});

it('prefixes a formula-like cell in the csv so a spreadsheet never evaluates it', function (): void {
    $contents = ($this->csv)(['Formel'], [['Formel' => '=1+1']]);

    expect($contents)->toContain("'=1+1")
        ->and($contents)->not->toContain(',=1+1')
        ->and($this->sanitizer->sanitizeSpreadsheetCell('=1+1'))->toBe("'=1+1");
});

it('prefixes every dangerous leading character and leaves harmless text alone', function (): void {
    expect($this->sanitizer->sanitizeSpreadsheetCell('+1'))->toBe("'+1")
        ->and($this->sanitizer->sanitizeSpreadsheetCell('-1'))->toBe("'-1")
        ->and($this->sanitizer->sanitizeSpreadsheetCell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and($this->sanitizer->sanitizeSpreadsheetCell("\tTab"))->toBe("'\tTab")
        ->and($this->sanitizer->sanitizeSpreadsheetCell('Acme GmbH'))->toBe('Acme GmbH')
        ->and($this->sanitizer->sanitizeSpreadsheetCell(''))->toBe('');
});

it('stores a formula-like cell as inert text in the xlsx', function (): void {
    $cells = ($this->xlsx)(['Formel'], [['Formel' => '=1+1']]);

    expect($cells['A2']['value'])->toBe("'=1+1")
        ->and($cells['A2']['type'])->toBe(DataType::TYPE_STRING);
});

it('keeps the raw value in the json export because no spreadsheet reads it', function (): void {
    $this->jsonWriter->write('local', 'exports/probe.json', ['Formel'], [['Formel' => '=1+1']]);

    expect(Storage::disk('local')->get('exports/probe.json'))->toBe('[{"Formel":"=1+1"}]');
});

it('keeps numbers, booleans and empty values typed in the json export', function (): void {
    $this->jsonWriter->write('local', 'exports/probe.json', ['Zahl', 'Flag', 'Leer'], [
        ['Zahl' => 42, 'Flag' => true, 'Leer' => null],
    ]);

    expect(Storage::disk('local')->get('exports/probe.json'))->toBe('[{"Zahl":42,"Flag":true,"Leer":null}]');
});

it('writes a negative sum as a plain number in a column that was declared numeric', function (): void {
    expect(($this->csv)(['Summe'], [['Summe' => '-12.5']], ['Summe']))->toContain("\n-12.5");
});

it('keeps the same value quoted in a column that was not declared numeric', function (): void {
    expect(($this->csv)(['Summe'], [['Summe' => '-12.5']]))->toContain("'-12.5");
});

it('falls back to the sanitized text path for a formula inside a numeric column', function (): void {
    expect(($this->csv)(['Summe'], [['Summe' => '=1+1']], ['Summe']))->toContain("'=1+1");
});

it('leaves a non numeric placeholder inside a numeric column as text', function (): void {
    $contents = ($this->csv)(['Summe'], [['Summe' => 'k. A.'], ['Summe' => '']], ['Summe']);

    expect($contents)->toContain('k. A.');
});

it('ignores a declared numeric header that no column carries', function (): void {
    expect(($this->csv)(['Summe'], [['Summe' => '-12.5']], ['Unbekannt']))->toContain("'-12.5");
});

it('writes a negative sum as a real number cell in a declared numeric xlsx column', function (): void {
    $cells = ($this->xlsx)(['Summe'], [['Summe' => '-12.5']], ['Summe']);

    expect($cells['A2']['value'])->toBe(-12.5)
        ->and($cells['A2']['type'])->toBe(DataType::TYPE_NUMERIC);
});

it('keeps the same xlsx value as text when its column was not declared numeric', function (): void {
    $cells = ($this->xlsx)(['Summe'], [['Summe' => '-12.5']]);

    expect($cells['A2']['value'])->toBe("'-12.5")
        ->and($cells['A2']['type'])->toBe(DataType::TYPE_STRING);
});

it('turns a value that is neither scalar nor null into json before it reaches a cell', function (): void {
    expect($this->sanitizer->stringify(['a' => 1]))->toBe('{"a":1}')
        ->and($this->sanitizer->stringify(null))->toBe('')
        ->and($this->sanitizer->stringify(7))->toBe('7');
});

it('counts the written rows rather than the headers', function (): void {
    $rows = [['Name' => 'Acme'], ['Name' => 'Globex'], ['Name' => 'Initech']];

    expect($this->csvWriter->write('local', 'exports/count.csv', ['Name'], $rows))->toBe(3)
        ->and($this->jsonWriter->write('local', 'exports/count.json', ['Name'], $rows))->toBe(3)
        ->and($this->xlsxWriter->write('local', 'exports/count.xlsx', ['Name'], $rows))->toBe(3);
});
