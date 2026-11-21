<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\ImportJob;
use Closure;
use Generator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PreparedImportRows
{
    private int $chunkSize = 250;

    /**
     * @param  Closure(): iterable<int, array<string, mixed>>  $source
     */
    public function prepare(ImportJob $job, Closure $source): void
    {
        $storage = Storage::disk($job->source_disk);
        $directory = $this->directory($job);

        if ($storage->exists("{$directory}/manifest.json")) {
            return;
        }

        $generation = (string) Str::ulid();
        $staging = "{$directory}/{$generation}";
        try {
            $count = $this->writeParts($job->source_disk, $staging, $source());

            if ($count !== $job->total_rows) {
                throw new RuntimeException('The import source row count changed after preparation.');
            }

            if (!$storage->put("{$staging}/manifest.json", json_encode(['rows' => $count, 'chunk_size' => $this->chunkSize, 'generation' => $generation], JSON_THROW_ON_ERROR))
                || !$storage->move("{$staging}/manifest.json", "{$directory}/manifest.json")) {
                throw new RuntimeException('Unable to persist the prepared import manifest.');
            }
        } catch (Throwable $exception) {
            $storage->deleteDirectory($staging);

            throw $exception;
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(ImportJob $job, int $offset, int $limit): Generator
    {
        $storage = Storage::disk($job->source_disk);
        $directory = $this->directory($job);
        $manifest = json_decode($storage->get("{$directory}/manifest.json") ?? '', true, flags: JSON_THROW_ON_ERROR);
        $size = (int) $manifest['chunk_size'];
        $directory .= '/'.$manifest['generation'];
        $end = min($offset + $limit, (int) $manifest['rows']);

        for ($start = $offset; $start < $end;) {
            $part = intdiv($start, $size);
            /** @var list<array<string, mixed>> $rows */
            $rows = json_decode($storage->get("{$directory}/{$part}.json") ?? '', true, flags: JSON_THROW_ON_ERROR);
            $partEnd = min(($part + 1) * $size, $end);

            while ($start < $partEnd) {
                yield $start => $rows[$start % $size];
                $start++;
            }
        }
    }

    public function delete(ImportJob $job): void
    {
        Storage::disk($job->source_disk)->deleteDirectory($this->directory($job));
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function writeParts(string $disk, string $directory, iterable $rows): int
    {
        $buffer = [];
        $count = 0;

        foreach ($rows as $row) {
            $buffer[] = $row;
            $count++;

            if (count($buffer) === $this->chunkSize) {
                $this->store($disk, $directory, intdiv($count - 1, $this->chunkSize), $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->store($disk, $directory, intdiv($count - 1, $this->chunkSize), $buffer);
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(string $disk, string $directory, int $part, array $rows): void
    {
        $path = "{$directory}/{$part}.json";

        if (!Storage::disk($disk)->put($path, json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))) {
            throw new RuntimeException('Unable to persist prepared import rows.');
        }
    }

    private function directory(ImportJob $job): string
    {
        return "imports/{$job->tenant_id}/{$job->user_id}/prepared/{$job->getKey()}";
    }
}
