<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\Authorization\TeamHierarchyException;
use App\Models\Team;
use App\Support\Authorization\TeamTreeMaterializer;

class TeamTreeObserver
{
    public function __construct(
        private readonly TeamTreeMaterializer $materializer,
    ) {}

    /**
     * @throws TeamHierarchyException
     */
    public function creating(Team $team): void
    {
        if ($team->parent_team_id === null) {
            return;
        }

        $parent = Team::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->whereKey($team->parent_team_id)
            ->first();

        if ($parent === null) {
            throw TeamHierarchyException::parentNotFound();
        }

        $this->materializer->assertParentIsAssignable($team, $parent);
    }

    /**
     * @throws TeamHierarchyException
     */
    public function created(Team $team): void
    {
        if ($team->parent_team_id === null) {
            return;
        }

        $this->materializer->rematerialize($team);
    }

    /**
     * @throws TeamHierarchyException
     */
    public function updating(Team $team): void
    {
        if ($team->isDirty('parent_team_id')) {
            throw TeamHierarchyException::reparentRequiresMaterializer();
        }

        if ($team->isDirty('tenant_id')) {
            throw TeamHierarchyException::scopeIsImmutable();
        }
    }

    /**
     * @throws TeamHierarchyException
     */
    public function deleted(Team $team): void
    {
        if ($team->isForceDeleting()) {
            return;
        }

        $this->materializer->rematerialize($team);
    }

    /**
     * @throws TeamHierarchyException
     */
    public function restored(Team $team): void
    {
        $this->materializer->rematerialize($team);
    }

    /**
     * @throws TeamHierarchyException
     */
    public function forceDeleted(Team $team): void
    {
        $this->materializer->rematerialize($team);
    }
}
