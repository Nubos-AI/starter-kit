<?php

declare(strict_types=1);

namespace App\Support\Import;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class XlsxReader
{
    private readonly Worksheet $worksheet;

    /**
     * @var list<string>
     */
    private readonly array $sheetNames;

    public function __construct(string $absolutePath, ?string $sheet = null)
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        $this->sheetNames = array_values($reader->listWorksheetNames($absolutePath));

        $targetSheet = $sheet ?? ($this->sheetNames[0] ?? null);

        if ($targetSheet !== null) {
            $reader->setLoadSheetsOnly($targetSheet);
        }

        $spreadsheet = $reader->load($absolutePath);
        $worksheet = $targetSheet !== null ? $spreadsheet->getSheetByName($targetSheet) : $spreadsheet->getActiveSheet();

        $this->worksheet = $worksheet ?? $spreadsheet->getActiveSheet();
    }

    /**
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return $this->sheetNames;
    }

    /**
     * @return list<string>
     */
    public function header(): array
    {
        foreach ($this->worksheet->getRowIterator(1, 1) as $row) {
            $header = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $header[] = (string) $cell->getValue();
            }

            return $this->trimTrailingEmpty($header);
        }

        return [];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(): Generator
    {
        $header = $this->header();

        if ($header === []) {
            return;
        }

        foreach ($this->worksheet->getRowIterator(2) as $row) {
            $values = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $values[] = $cell->getValue();
            }

            if ($this->isEmptyRow($values)) {
                continue;
            }

            $record = [];

            foreach ($header as $index => $key) {
                $record[$key] = $values[$index] ?? null;
            }

            yield $record;
        }
    }

    /**
     * @param  list<string>  $header
     * @return list<string>
     */
    private function trimTrailingEmpty(array $header): array
    {
        while ($header !== [] && trim(end($header)) === '') {
            array_pop($header);
        }

        return $header;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
