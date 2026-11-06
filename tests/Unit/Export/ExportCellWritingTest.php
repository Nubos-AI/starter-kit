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

it('prefixes every dangerous leading character and leaves harmless text alone', function (string $cell, string $expected): void {
    expect($this->sanitizer->sanitizeSpreadsheetCell($cell))->toBe($expected);
})->with([
    'equals sign' => ['=1+1', "'=1+1"],
    'plus sign' => ['+1', "'+1"],
    'minus sign' => ['-1', "'-1"],
    'at sign' => ['@SUM(A1)', "'@SUM(A1)"],
    'tab' => ["\tTab", "'\tTab"],
    'harmless text' => ['Acme GmbH', 'Acme GmbH'],
    'empty text' => ['', ''],
]);

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

it('writes a csv cell as a plain number only inside a column that was declared numeric', function (array $rows, array $numericHeaders, string $expected): void {
    expect(($this->csv)(['Summe'], $rows, $numericHeaders))->toContain($expected);
})->with([
    'negative sum in a numeric column' => [[['Summe' => '-12.5']], ['Summe'], "\n-12.5"],
    'same value in a column not declared numeric' => [[['Summe' => '-12.5']], [], "'-12.5"],
    'formula inside a numeric column' => [[['Summe' => '=1+1']], ['Summe'], "'=1+1"],
    'non numeric placeholder inside a numeric column' => [[['Summe' => 'k. A.'], ['Summe' => '']], ['Summe'], 'k. A.'],
    'declared numeric header no column carries' => [[['Summe' => '-12.5']], ['Unbekannt'], "'-12.5"],
]);

it('writes an xlsx cell as a real number only inside a column that was declared numeric', function (array $numericHeaders, string|float $expectedValue, string $expectedType): void {
    $cells = ($this->xlsx)(['Summe'], [['Summe' => '-12.5']], $numericHeaders);

    expect($cells['A2']['value'])->toBe($expectedValue)
        ->and($cells['A2']['type'])->toBe($expectedType);
})->with([
    'declared numeric column' => [['Summe'], -12.5, DataType::TYPE_NUMERIC],
    'column not declared numeric' => [[], "'-12.5", DataType::TYPE_STRING],
]);

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

it('round trips unicode, XML characters, whitespace and escaped control characters in streamed xlsx strings', function (): void {
    $text = "  Grüße <&> 東京 😀\nSecond line\tend\x01_x0002_  ";
    $cells = ($this->xlsx)(['=Header', 'Number'], [['=Header' => $text, 'Number' => 42]], ['Number']);

    expect($cells['A1']['value'])->toBe('=Header')
        ->and($cells['A1']['type'])->toBe(DataType::TYPE_STRING)
        ->and($cells['A2']['value'])->toBe($text)
        ->and($cells['B2']['value'])->toBe(42);
});

it('keeps memory bounded while consuming a large generator of unique xlsx cells', function (): void {
    $baseline = null;
    $growth = 0;
    $rows = (static function () use (&$baseline, &$growth): Generator {
        for ($index = 0; $index < 20000; $index++) {
            $baseline ??= memory_get_usage(true);
            $growth = max($growth, memory_get_usage(true) - $baseline);

            yield ['Name' => str_repeat("Row {$index} ", 20), 'Value' => $index, 'Note' => "Text {$index}"];
        }
    })();

    expect($this->xlsxWriter->write('local', 'exports/streamed.xlsx', ['Name', 'Value', 'Note'], $rows, ['Value']))->toBe(20000)
        ->and($growth)->toBeLessThan(8 * 1024 * 1024);

    $archive = new ZipArchive;
    expect($archive->open(Storage::disk('local')->path('exports/streamed.xlsx')))->toBeTrue()
        ->and($archive->locateName('[Content_Types].xml'))->not->toBeFalse()
        ->and($archive->locateName('xl/_rels/workbook.xml.rels'))->not->toBeFalse()
        ->and($archive->locateName('xl/sharedStrings.xml'))->not->toBeFalse();
    $archive->close();
});

it('does not publish an incomplete xlsx when the row generator fails', function (): void {
    $rows = (static function (): Generator {
        yield ['Name' => 'First'];

        throw new RuntimeException('Read failed');
    })();

    expect(fn () => $this->xlsxWriter->write('local', 'exports/failed.xlsx', ['Name'], $rows))->toThrow(RuntimeException::class, 'Read failed');
    Storage::disk('local')->assertMissing('exports/failed.xlsx');
});

it('retains spreadsheet cell length and line ending normalization while streaming', function (): void {
    $cells = ($this->xlsx)(['Long', 'Lines'], [['Long' => str_repeat('ä', 33000), 'Lines' => "one\r\ntwo\rthree"]]);

    expect(mb_strlen($cells['A2']['value']))->toBe(32767)
        ->and($cells['B2']['value'])->toBe("one\ntwo\nthree");
});
