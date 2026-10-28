<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Modules\RecordExtensions;

class RecordPresenter
{
    public function __construct(
        private readonly RecordTitleResolver $titles,
        private readonly FieldVisibilityResolver $visibility,
        private readonly RecordExtensions $extensions,
    ) {}

    /** @return array<string, mixed> */
    public function extensions(CustomRecord $record): array
    {
        return $this->extensions->resource($record);
    }

    public function title(CustomRecord $record, ?User $viewer): string
    {
        return $viewer === null
            ? (string) ($record->record_number ?? $record->getKey())
            : $this->titles->titleFor($viewer, $record);
    }

    /** @return array<string, mixed> */
    public function visibleData(CustomRecord $record, ?User $viewer): array
    {
        $data = $record->data ?? [];

        if ($viewer === null) {
            return $data;
        }

        $forbidden = $this->visibility->forbiddenReadFieldKeys($viewer, $record->object_type_id);

        return array_diff_key($data, array_flip($forbidden));
    }
}
