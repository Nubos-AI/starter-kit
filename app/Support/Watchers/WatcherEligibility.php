<?php

declare(strict_types=1);

namespace App\Support\Watchers;

use App\Models\CustomRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WatcherEligibility
{
    public function __construct(private readonly WatcherDirectory $directory) {}

    /**
     * @param  list<string>  $userIds
     *
     * @throws ValidationException
     */
    public function assertMayWatch(CustomRecord $record, array $userIds, string $field, string $message): void
    {
        if ($userIds === []) {
            return;
        }

        $candidates = $this->directory->candidatesFor($record, $userIds);

        foreach ($userIds as $userId) {
            $candidate = $candidates->get($userId);

            if (!$candidate instanceof User || !Gate::forUser($candidate)->allows('view', $record)) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    }
}
