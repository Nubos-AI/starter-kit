<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;

class ShadowedRecordFieldKeys
{
    /** @var list<string> */
    private array $spine = ['id', 'created_at', 'updated_at', 'deleted_at', 'pivot'];

    /** @var list<string>|null */
    private ?array $keys = null;

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $record = new CustomRecord;

        return $this->keys ??= array_values(array_unique([
            ...$this->spine,
            ...array_keys($record->getCasts()),
            ...$record->getFillable(),
            ...array_filter(
                get_class_methods($record),
                static fn (string $method): bool => preg_match('/^[a-z][a-z0-9_]*$/', $method) === 1,
            ),
        ]));
    }
}
