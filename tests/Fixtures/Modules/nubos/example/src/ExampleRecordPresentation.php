<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\nubos\example\src;

use App\Contracts\Modules\RecordPresentationExtensionInterface;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;

class ExampleRecordPresentation implements RecordPresentationExtensionInterface
{
    /** @return array<string, mixed> */
    public function form(ObjectType $objectType, User $user, ?CustomRecord $record): array
    {
        return ['example' => 'form'];
    }

    /** @return array<string, mixed> */
    public function index(ObjectType $objectType, User $user): array
    {
        return ['example' => 'index'];
    }

    /** @return array<string, mixed> */
    public function resource(CustomRecord $record): array
    {
        return ['example' => 'resource'];
    }
}
