<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;

interface RecordPresentationExtensionInterface
{
    /** @return array<string, mixed> */
    public function form(ObjectType $objectType, User $user, ?CustomRecord $record): array;

    /** @return array<string, mixed> */
    public function index(ObjectType $objectType, User $user): array;

    /** @return array<string, mixed> */
    public function resource(CustomRecord $record): array;
}
