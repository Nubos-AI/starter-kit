<?php

declare(strict_types=1);

namespace App\Activities\Users;

use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\Contracts\Users\PurgeUnverifiedRegistrationsActivityInterface;
use App\Enums\Users\RegistrationReleaseReason;
use App\Exceptions\Tenancy\TenantPurgeFailedException;
use App\Models\User;
use App\Support\Users\UnverifiedRegistrationDirectory;
use App\Traits\Temporal\DescribesWorkflowExecution;
use Illuminate\Support\Facades\Log;

class PurgeUnverifiedRegistrationsActivity implements PurgeUnverifiedRegistrationsActivityInterface
{
    use DescribesWorkflowExecution;

    public function __construct(
        private readonly UnverifiedRegistrationDirectory $registrations,
        private readonly ReleaseUnverifiedRegistrationAction $releaseRegistration,
    ) {}

    /**
     * @return list<string>
     */
    public function abandonedRegistrationIds(): array
    {
        return $this->registrations->abandonedIds(
            now()->subDays((int) config('users.registration.unverified_ttl_days')),
            (int) config('users.registration.purge_batch_size'),
        );
    }

    /**
     * @throws TenantPurgeFailedException
     */
    public function releaseRegistration(string $userId): bool
    {
        $user = User::query()->find($userId);

        if (!$user instanceof User) {
            Log::info('An unverified registration was gone before the purge reached it.', ['user_id' => $userId, ...$this->workflowExecution()]);

            return false;
        }

        return $this->releaseRegistration->execute($user, RegistrationReleaseReason::Expired);
    }
}
