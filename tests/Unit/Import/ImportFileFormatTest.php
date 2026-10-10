<?php

declare(strict_types=1);

use App\Enums\Import\ImportColumnFormat;
use App\Support\Import\ColumnFormatDetector;
use App\Support\Import\CsvReader;
use App\Support\Import\FileFormatDetector;
use App\Support\Import\XlsxReader;
use PhpOffice\PhpSpreadsheet\Exception as PhpSpreadsheetException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->fileDetector = new FileFormatDetector;
    $this->columnDetector = new ColumnFormatDetector;
    $this->temporaryPaths = [];

    /** @var callable(string, string):string */
    $this->fileWith = function (string $contents, string $extension = 'csv'): string {
        $path = (string) tempnam(sys_get_temp_dir(), 'import_probe_').'.'.$extension;
        file_put_contents($path, $contents);
        $this->temporaryPaths[] = $path;

        return $path;
    };

    /** @var callable(array<string, list<list<string>>>):string */
    $this->workbookWith = function (array $sheets): string {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);

            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $columnIndex => $cell) {
                    $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $cell);
                }
            }
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'import_probe_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->temporaryPaths[] = $path;

        return $path;
    };
});

afterEach(function (): void {
    foreach ($this->temporaryPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('parses a csv with quoted delimiters, embedded quotes and windows line endings', function (): void {
    $path = ($this->fileWith)("name;note\r\n\"Acme; Inc\";\"said \"\"hi\"\"\"\r\n");

    $reader = new CsvReader($path, ';');

    expect($reader->header())->toBe(['name', 'note'])
        ->and(iterator_to_array($reader->rows()))->toBe([
            ['name' => 'Acme; Inc', 'note' => 'said "hi"'],
        ]);
});

it('tells a semicolon separated german csv from a comma separated one', function (): void {
    expect($this->fileDetector->detect(($this->fileWith)("name;stadt\nAcme;Köln\n"))->delimiter)->toBe(';')
        ->and($this->fileDetector->detect(($this->fileWith)("name,stadt\nAcme,Köln\n"))->delimiter)->toBe(',');
});

it('detects utf-8 with a byte order mark and keeps the umlauts intact', function (): void {
    $path = ($this->fileWith)("\xEF\xBB\xBFname\nKöln\n");

    expect($this->fileDetector->detect($path)->encoding)->toBe('UTF-8')
        ->and(iterator_to_array((new CsvReader($path))->rows()))->toBe([['name' => 'Köln']]);
});

it('recognises a header row and rejects one that is only numbers', function (): void {
    expect($this->fileDetector->detect(($this->fileWith)("name,stadt\nAcme,Köln\n"))->hasHeader)->toBeTrue()
        ->and($this->fileDetector->detect(($this->fileWith)("1,2\n3,4\n"))->hasHeader)->toBeFalse();
});

it('detects a column of german dates and normalizes it to the canonical date', function (): void {
    $format = $this->columnDetector->detectColumn(['01.02.2026', '28.02.2026']);

    expect($format)->toBe(ImportColumnFormat::Date)
        ->and($this->columnDetector->normalize('01.02.2026', $format))->toBe('2026-02-01');
});

it('keeps midnight for a date only value instead of taking the time of the import', function (): void {
    expect($this->columnDetector->normalize('01.02.2026', ImportColumnFormat::DateTime))->toBe('2026-02-01 00:00:00');
});

it('normalizes the iso date time the export writes', function (): void {
    expect($this->columnDetector->normalize('2026-02-01T13:45:00', ImportColumnFormat::DateTime))->toBe('2026-02-01 13:45:00');
});

it('detects a column of german amounts and normalizes it to a plain numeric string', function (): void {
    $format = $this->columnDetector->detectColumn(['1.234,50', '99,00']);

    expect($format)->toBe(ImportColumnFormat::Decimal)
        ->and($this->columnDetector->normalize('1.234,50', $format))->toBe('1234.50')
        ->and($this->columnDetector->normalize('1.234,50 €', ImportColumnFormat::Money))->toBe('1234.50');
});

it('detects a column of whole numbers as integer and rounds on normalization', function (): void {
    $format = $this->columnDetector->detectColumn(['7', '12']);

    expect($format)->toBe(ImportColumnFormat::Integer)
        ->and($this->columnDetector->normalize('7,6', $format))->toBe(8);
});

it('falls back to the raw string for a value the format cannot parse', function (): void {
    expect($this->columnDetector->normalize('keine Angabe', ImportColumnFormat::Integer))->toBe('keine Angabe')
        ->and($this->columnDetector->normalize('irgendwann', ImportColumnFormat::Date))->toBe('irgendwann')
        ->and($this->columnDetector->normalize('vielleicht', ImportColumnFormat::Boolean))->toBe('vielleicht');
});

it('reads a blank cell as no value at all', function (): void {
    expect($this->columnDetector->normalize('   ', ImportColumnFormat::Integer))->toBeNull()
        ->and($this->columnDetector->normalize(null, ImportColumnFormat::Text))->toBeNull();
});

it('accepts both the json array the export writes and a comma separated list for a multi select', function (): void {
    expect($this->columnDetector->normalize('["a","b"]', ImportColumnFormat::MultiSelect))->toBe(['a', 'b'])
        ->and($this->columnDetector->normalize('a, b', ImportColumnFormat::MultiSelect))->toBe(['a', 'b']);
});

it('reads german truth words as booleans', function (): void {
    expect($this->columnDetector->normalize('Ja', ImportColumnFormat::Boolean))->toBeTrue()
        ->and($this->columnDetector->normalize('nein', ImportColumnFormat::Boolean))->toBeFalse();
});

it('treats a column of mixed dates and numbers as text', function (): void {
    expect($this->columnDetector->detectColumn(['01.02.2026', '17']))->toBe(ImportColumnFormat::Text)
        ->and($this->columnDetector->detectColumn(['', '   ']))->toBe(ImportColumnFormat::Text);
});

it('exposes every sheet name of a workbook but reads only the chosen one', function (): void {
    $path = ($this->workbookWith)([
        'Kunden' => [['name'], ['Acme']],
        'Lieferanten' => [['name'], ['Globex']],
    ]);

    expect((new XlsxReader($path))->sheetNames())->toBe(['Kunden', 'Lieferanten'])
        ->and(iterator_to_array((new XlsxReader($path, 'Lieferanten'))->rows()))->toBe([['name' => 'Globex']]);
});

it('refuses a workbook sheet the caller named but the workbook does not have', function (): void {
    $path = ($this->workbookWith)(['Kunden' => [['name'], ['Acme']]]);

    expect(fn (): XlsxReader => new XlsxReader($path, 'Fehlt'))->toThrow(PhpSpreadsheetException::class);
});
