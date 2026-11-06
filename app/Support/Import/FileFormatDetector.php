<?php

declare(strict_types=1);

namespace App\Support\Import;

class FileFormatDetector
{
    /**
     * @var int<1, max>
     */
    private int $sampleBytes = 8192;

    /**
     * @var list<string>
     */
    private array $delimiterCandidates = [',', ';', "\t", '|'];

    /**
     * @var list<string>
     */
    private array $encodingCandidates = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    /**
     * @return object{encoding: string, delimiter: string, hasHeader: bool, sheets: list<string>}
     */
    public function detect(string $absolutePath): object
    {
        $sample = $this->readSample($absolutePath);
        $encoding = $this->detectEncoding($sample);
        $firstLine = $this->firstLine($sample);
        $delimiter = $this->detectDelimiter($firstLine);

        return new class($encoding, $delimiter, $this->looksLikeHeader($firstLine, $delimiter), [])
        {
            /**
             * @param  list<string>  $sheets
             */
            public function __construct(
                public readonly string $encoding,
                public readonly string $delimiter,
                public readonly bool $hasHeader,
                public readonly array $sheets,
            ) {}
        };
    }

    private function readSample(string $absolutePath): string
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return '';
        }

        $sample = (string) fread($handle, $this->sampleBytes);
        fclose($handle);

        return $sample;
    }

    private function detectEncoding(string $sample): string
    {
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }

        if (str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF")) {
            return 'UTF-16';
        }

        $detected = mb_detect_encoding($sample, $this->encodingCandidates, true);

        return $detected === false ? 'UTF-8' : $detected;
    }

    private function firstLine(string $sample): string
    {
        $stripped = ltrim($sample, "\xEF\xBB\xBF");
        $end = strcspn($stripped, "\r\n");

        return substr($stripped, 0, $end);
    }

    private function detectDelimiter(string $firstLine): string
    {
        $best = ',';
        $bestCount = -1;

        foreach ($this->delimiterCandidates as $candidate) {
            $count = substr_count($firstLine, $candidate);

            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function looksLikeHeader(string $firstLine, string $delimiter): bool
    {
        if ($firstLine === '' || $delimiter === '') {
            return false;
        }

        $cells = array_map(
            static fn (string $cell): string => trim($cell, " \"'"),
            explode($delimiter, $firstLine),
        );

        foreach ($cells as $cell) {
            if ($cell === '' || is_numeric($cell)) {
                return false;
            }
        }

        return true;
    }
}
