<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'UnverifiedRegistrationPurge.')]
interface PurgeUnverifiedRegistrationsActivityInterface
{
    /**
     * @return list<string>
     */
    #[ActivityMethod(name: 'abandonedRegistrationIds')]
    public function abandonedRegistrationIds(): array;

    #[ActivityMethod(name: 'releaseRegistration')]
    public function releaseRegistration(string $userId): bool;
}
