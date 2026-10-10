<?php

declare(strict_types=1);

namespace App\Support\Engine;

class RecordDiff
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<int, string>  $encryptedKeys
     * @return list<array{field_key: string, old: mixed, new: mixed}>
     */
    public function between(array $old, array $new, array $encryptedKeys = []): array
    {
        $keys = array_keys($old + $new);
        sort($keys);

        $changes = [];

        foreach ($keys as $key) {
            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            if (in_array($key, $encryptedKeys, true)) {
                $changes[] = [
                    'field_key' => $key,
                    'old' => $this->redact($oldValue),
                    'new' => $this->redact($newValue),
                ];

                continue;
            }

            $changes[] = [
                'field_key' => $key,
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    private function redact(mixed $value): ?string
    {
        return $value === null ? null : (string) config('engine.diff.redacted_placeholder');
    }
}
