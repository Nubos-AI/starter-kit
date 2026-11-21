<?php

declare(strict_types=1);

namespace App\Support\Export;

use Illuminate\Support\Facades\Storage;

class JsonExportWriter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function write(string $disk, string $path, array $headers, iterable $rows): int
    {
        $records = [];
        $rowCount = 0;

        foreach ($rows as $row) {
            $record = [];

            foreach ($headers as $header) {
                $record[$header] = $row[$header] ?? null;
            }

            $records[] = $record;
            $rowCount++;
        }

        Storage::disk($disk)->put($path, (string) json_encode($records));

        return $rowCount;
    }
}
