<?php

declare(strict_types=1);

namespace App\Policies\Activities;

use App\Models\CustomRecord;
use App\Models\RecordActivity;
use App\Models\User;
use App\Support\Links\SourceLinkRegistry;
use Illuminate\Support\Facades\Gate;

class RecordActivityPolicy
{
    public function __construct(private readonly SourceLinkRegistry $sourceLinkRegistry) {}

    public function create(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function view(User $user, RecordActivity $activity): bool
    {
        if ($activity->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($activity->record_id !== null) {
            return $this->allowsOnRecord($user, $activity, 'view');
        }

        return $this->isParticipant($user, $activity) || $this->mayOpenSource($user, $activity);
    }

    public function update(User $user, RecordActivity $activity): bool
    {
        if ($activity->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($activity->record_id !== null) {
            return $this->allowsOnRecord($user, $activity, 'update');
        }

        return $this->isParticipant($user, $activity);
    }

    public function delete(User $user, RecordActivity $activity): bool
    {
        return $this->update($user, $activity);
    }

    private function allowsOnRecord(User $user, RecordActivity $activity, string $ability): bool
    {
        $record = $activity->record;

        return $record instanceof CustomRecord && Gate::forUser($user)->allows($ability, $record);
    }

    private function isParticipant(User $user, RecordActivity $activity): bool
    {
        return in_array((string) $user->getKey(), [$activity->creator_id, $activity->assignee_id], true);
    }

    private function mayOpenSource(User $user, RecordActivity $activity): bool
    {
        if ($activity->source_type === null || $activity->source_id === null || !$this->sourceLinkRegistry->has($activity->source_type)) {
            return false;
        }

        return $this->sourceLinkRegistry
            ->resolverFor($activity->source_type)
            ->isAccessible($user, $activity->source_id);
    }
}
