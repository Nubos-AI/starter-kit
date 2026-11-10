<?php

declare(strict_types=1);

namespace App\Policies\Segments;

use App\Models\Segment;
use App\Models\SegmentShare;
use App\Models\User;
use App\Support\Authorization\TenantBoundary;
use App\Support\Segments\SegmentGrantSource;
use App\Support\Sharing\ShareGranteeMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SegmentPolicy
{
    public function __construct(
        private readonly TenantBoundary $tenantBoundary,
        private readonly SegmentGrantSource $grants,
        private readonly ShareGranteeMatcher $grantees,
    ) {}

    public function view(User $user, Segment $segment): bool
    {
        if (!$this->tenantBoundary->admits($user, $segment)) {
            return false;
        }

        return $segment->is_system
            || $segment->is_default
            || $this->isOwner($user, $segment)
            || $this->hasGrant($user, $segment, 'view');
    }

    public function update(User $user, Segment $segment): bool
    {
        if (!$this->tenantBoundary->admits($user, $segment)) {
            return false;
        }

        if ($segment->is_system) {
            return false;
        }

        return $this->isOwner($user, $segment)
            || $this->hasGrant($user, $segment, 'update');
    }

    public function delete(User $user, Segment $segment): bool
    {
        if (!$this->tenantBoundary->admits($user, $segment)) {
            return false;
        }

        if ($segment->is_system) {
            return false;
        }

        return $this->isOwner($user, $segment)
            || $user->isEscalatedAuthority()
            || $this->hasGrant($user, $segment, 'delete');
    }

    public function share(User $user, Segment $segment): bool
    {
        if (!$this->tenantBoundary->admits($user, $segment)) {
            return false;
        }

        if ($segment->is_system) {
            return false;
        }

        return $this->isOwner($user, $segment)
            || $user->isEscalatedAuthority();
    }

    public function manageDefaults(User $user, Segment $segment): bool
    {
        if (!$this->tenantBoundary->admits($user, $segment)) {
            return false;
        }

        if ($segment->is_system) {
            return false;
        }

        return $user->isEscalatedAuthority();
    }

    private function isOwner(User $user, Segment $segment): bool
    {
        return $segment->owner_id === $user->getKey();
    }

    private function hasGrant(User $user, Segment $segment, string $ability): bool
    {
        if ($ability === 'delete') {
            return false;
        }

        return $this->grantsFor($segment)->contains(
            fn (SegmentShare $grant): bool => $this->grantMatchesViewer($grant, $user)
                && ($ability !== 'update' || $grant->can_edit),
        );
    }

    private function grantMatchesViewer(SegmentShare $grant, User $user): bool
    {
        return $this->grantees->matches(
            $grant->grantee_type,
            (string) $grant->grantee_id,
            $user,
            fn (string $teamId): bool => $this->grants->holdsTeam($user, $teamId),
        );
    }

    /**
     * @return EloquentCollection<int, SegmentShare>
     */
    private function grantsFor(Segment $segment): EloquentCollection
    {
        return $this->grants->forSegment($segment);
    }
}
