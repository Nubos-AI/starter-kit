<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Exceptions\Authorization\TeamHierarchyException;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class TeamTreeMaterializer
{
    public static int $maxTreeNodes = 10000;

    /**
     * @throws TeamHierarchyException
     * @throws Throwable
     */
    public function attach(Team $team, ?Team $parent): void
    {
        $parentId = $parent === null ? null : (string) $parent->getKey();

        if ($team->getRawOriginal('parent_team_id', $team->parent_team_id) === $parentId) {
            return;
        }

        DB::transaction(function () use ($team, $parent, $parentId): void {
            $rows = $this->lockTenant($team);

            $this->assertNodeCap($rows);

            if ($parent !== null) {
                $this->assertEdgeIsAssignable($team, $parent, $rows);
            }

            $row = $rows->firstWhere('id', (string) $team->getKey());

            if ($row === null) {
                throw TeamHierarchyException::teamNotFound();
            }

            $row->forceFill(['parent_team_id' => $parentId])->saveQuietly();

            $this->materialize($rows, [(string) $team->getKey() => $parentId]);

            $this->refreshDerivedArrays($team, $rows);
        });
    }

    /**
     * @throws TeamHierarchyException
     * @throws Throwable
     */
    public function rematerialize(Team $team): void
    {
        DB::transaction(function () use ($team): void {
            $rows = $this->lockTenant($team);

            $this->assertNodeCap($rows);

            $this->materialize($rows, []);

            $this->refreshDerivedArrays($team, $rows);
        });
    }

    /**
     * @throws TeamHierarchyException
     */
    public function assertParentIsAssignable(Team $team, Team $parent): void
    {
        if ((string) $parent->getAttribute('tenant_id') !== (string) $team->getAttribute('tenant_id')) {
            throw TeamHierarchyException::parentInDifferentTenant();
        }

        if ($parent->getAttribute('deleted_at') !== null) {
            throw TeamHierarchyException::parentIsTrashed();
        }
    }

    /**
     * @return Collection<int, Team>
     */
    public function lockTenant(Team $team): Collection
    {
        return Team::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('tenant_id', $team->getRawOriginal('tenant_id', $team->tenant_id))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Team>  $rows
     *
     * @throws TeamHierarchyException
     */
    public function assertNodeCap(Collection $rows): void
    {
        if ($rows->count() > self::$maxTreeNodes) {
            throw TeamHierarchyException::tenantTreeTooLarge($rows->count());
        }
    }

    /**
     * @param  Collection<int, Team>  $rows
     *
     * @throws TeamHierarchyException
     */
    private function assertEdgeIsAssignable(Team $team, Team $parent, Collection $rows): void
    {
        $teamId = (string) $team->getKey();
        $parentId = (string) $parent->getKey();

        if ($teamId === $parentId) {
            throw TeamHierarchyException::cannotParentToSelf();
        }

        $locked = $rows->firstWhere('id', $parentId);

        if ($locked === null) {
            $this->refuseUnlockedParent($team, $parentId);
        }

        if ($locked->deleted_at !== null) {
            throw TeamHierarchyException::parentIsTrashed();
        }

        $this->assertEdgeClosesNoCycle($teamId, $parentId, $this->parentMap($rows, []));
    }

    /**
     * @throws TeamHierarchyException
     */
    private function refuseUnlockedParent(Team $team, string $parentId): never
    {
        $stored = Team::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->whereKey($parentId)
            ->first();

        if ($stored !== null) {
            $this->assertParentIsAssignable($team, $stored);
        }

        throw TeamHierarchyException::parentNotFound();
    }

    /**
     * @param  array<string, string|null>  $parents
     *
     * @throws TeamHierarchyException
     */
    public function assertEdgeClosesNoCycle(string $teamId, string $parentId, array $parents): void
    {
        $visited = [];
        $current = $parentId;

        while ($current !== null) {
            if ($current === $teamId) {
                throw TeamHierarchyException::cannotParentToDescendant();
            }

            if (isset($visited[$current])) {
                throw TeamHierarchyException::cycleDetected();
            }

            $visited[$current] = true;
            $current = $parents[$current] ?? null;
        }
    }

    /**
     * @param  Collection<int, Team>  $rows
     * @param  array<string, string|null>  $overrides
     *
     * @throws TeamHierarchyException
     */
    private function materialize(Collection $rows, array $overrides): void
    {
        $derived = $this->derivedArraysFor($rows, $overrides);

        foreach ($rows as $row) {
            $id = (string) $row->getKey();

            $changes = [];

            if ($row->ancestor_team_ids !== $derived[$id]['ancestor_team_ids']) {
                $changes['ancestor_team_ids'] = $derived[$id]['ancestor_team_ids'];
            }

            if ($row->descendant_team_ids !== $derived[$id]['descendant_team_ids']) {
                $changes['descendant_team_ids'] = $derived[$id]['descendant_team_ids'];
            }

            if ($changes !== []) {
                $row->forceFill($changes)->saveQuietly();
            }
        }
    }

    /**
     * @param  Collection<int, Team>  $rows
     * @param  array<string, string|null>  $overrides
     * @return array<string, array{ancestor_team_ids: list<string>, descendant_team_ids: list<string>}>
     *
     * @throws TeamHierarchyException
     */
    public function derivedArraysFor(Collection $rows, array $overrides): array
    {
        $parents = $this->parentMap($rows, $overrides);
        $isTrashed = [];
        $children = [];

        foreach ($rows as $row) {
            $isTrashed[(string) $row->getKey()] = $row->deleted_at !== null;
        }

        foreach ($parents as $id => $parentId) {
            if ($parentId !== null && array_key_exists($parentId, $parents)) {
                $children[$parentId][] = $id;
            }
        }

        $derived = [];

        foreach ($rows as $row) {
            $id = (string) $row->getKey();

            $derived[$id] = [
                'ancestor_team_ids' => $this->ancestorsOf($id, $parents, $isTrashed),
                'descendant_team_ids' => $this->descendantsOf($id, $children, $isTrashed),
            ];
        }

        return $derived;
    }

    /**
     * @param  Collection<int, Team>  $rows
     */
    private function refreshDerivedArrays(Team $team, Collection $rows): void
    {
        $row = $rows->firstWhere('id', (string) $team->getKey());

        if ($row === null) {
            return;
        }

        $team
            ->forceFill([
                'parent_team_id' => $row->parent_team_id,
                'ancestor_team_ids' => $row->ancestor_team_ids,
                'descendant_team_ids' => $row->descendant_team_ids,
            ])
            ->syncOriginalAttributes(['parent_team_id', 'ancestor_team_ids', 'descendant_team_ids']);
    }

    /**
     * @param  Collection<int, Team>  $rows
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    private function parentMap(Collection $rows, array $overrides): array
    {
        $parents = [];

        foreach ($rows as $row) {
            $id = (string) $row->getKey();
            $parents[$id] = array_key_exists($id, $overrides) ? $overrides[$id] : $row->parent_team_id;
        }

        return $parents;
    }

    /**
     * @param  array<string, string|null>  $parents
     * @param  array<string, bool>  $isTrashed
     * @return list<string>
     *
     * @throws TeamHierarchyException
     */
    private function ancestorsOf(string $id, array $parents, array $isTrashed): array
    {
        if ($isTrashed[$id]) {
            return [];
        }

        $chain = [];
        $visited = [$id => true];
        $current = $parents[$id];

        while ($current !== null) {
            if (isset($visited[$current])) {
                throw TeamHierarchyException::cycleDetected();
            }

            $visited[$current] = true;

            if (!array_key_exists($current, $parents) || $isTrashed[$current]) {
                break;
            }

            $chain[] = $current;
            $current = $parents[$current];
        }

        return $chain;
    }

    /**
     * @param  array<string, list<string>>  $children
     * @param  array<string, bool>  $isTrashed
     * @return list<string>
     *
     * @throws TeamHierarchyException
     */
    private function descendantsOf(string $id, array $children, array $isTrashed): array
    {
        if ($isTrashed[$id]) {
            return [];
        }

        $found = [];
        $visited = [$id => true];
        $queue = $children[$id] ?? [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($visited[$current])) {
                throw TeamHierarchyException::cycleDetected();
            }

            $visited[$current] = true;

            if ($isTrashed[$current]) {
                continue;
            }

            $found[] = $current;

            foreach ($children[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        sort($found);

        return $found;
    }
}
