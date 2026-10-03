<?php

declare(strict_types=1);

namespace App\Contracts\Trash;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'TrashPurge.')]
interface PurgeTrashedRecordsActivityInterface
{
    /**
     * @return list<string>
     */
    #[ActivityMethod(name: 'expiredRecordIds')]
    public function expiredRecordIds(): array;

    #[ActivityMethod(name: 'purgeRecord')]
    public function purgeRecord(string $recordId): bool;
}
