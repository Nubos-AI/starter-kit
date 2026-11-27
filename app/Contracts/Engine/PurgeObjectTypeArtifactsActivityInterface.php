<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\ObjectTypePurgeData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'object-types.')]
interface PurgeObjectTypeArtifactsActivityInterface
{
    #[ActivityMethod(name: 'dropObjectTypeIndexes')]
    public function dropObjectTypeIndexes(ObjectTypePurgeData $input): void;

    #[ActivityMethod(name: 'deleteObjectTypeFiles')]
    public function deleteObjectTypeFiles(ObjectTypePurgeData $input): int;
}
