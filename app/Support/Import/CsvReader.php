<?php

declare(strict_types=1);

namespace App\Support\Import;

use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;

class CsvReader
{
    /**
     * @var Reader<array<string, string|null>>
     */
    private readonly Reader $reader;

    public function __construct(string $absolutePath, string $delimiter = ',', string $encoding = 'UTF-8')
    {
        $reader = Reader::createFromPath($absolutePath, 'r');
        $reader->setDelimiter($delimiter);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);

        if (mb_strtoupper($encoding) !== 'UTF-8') {
            CharsetConverter::addTo($reader, $encoding, 'UTF-8');
        }

        $this->reader = $reader;
    }

    /**
     * @return list<string>
     */
    public function header(): array
    {
        return array_values(array_map(static fn (mixed $value): string => $value, $this->reader->getHeader()));
    }

    /**
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return [];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(): Generator
    {
        foreach ($this->reader->getRecords() as $record) {
            yield $record;
        }
    }
}
