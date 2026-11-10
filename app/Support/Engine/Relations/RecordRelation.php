<?php

declare(strict_types=1);

namespace App\Support\Engine\Relations;

use App\Actions\Engine\LinkRecordRelationAction;
use App\Actions\Engine\UnlinkRecordRelationAction;
use App\DTOs\Engine\RecordRelationDescriptor;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * @extends BelongsToMany<Model, CustomRecord, Pivot>
 */
class RecordRelation extends BelongsToMany
{
    private RecordRelationDescriptor $descriptor;

    public function forDescriptor(RecordRelationDescriptor $descriptor): static
    {
        $this->descriptor = $descriptor;

        return $this;
    }

    public function descriptor(): RecordRelationDescriptor
    {
        return $this->descriptor;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attach($id, array $attributes = [], $touch = true): void
    {
        $action = app(LinkRecordRelationAction::class);
        $actor = $this->actor();
        $parent = $this->getParent();

        foreach ($this->parseIds($id) as $targetId) {
            $action->execute($actor, $parent, [
                'relationship_type_id' => $this->descriptor->relationshipTypeId,
                'direction' => $this->descriptor->direction->value,
                'target_record_id' => (string) $targetId,
            ]);
        }
    }

    public function detach($ids = null, $touch = true): int
    {
        $action = app(UnlinkRecordRelationAction::class);
        $actor = $this->actor();
        $parent = $this->getParent();

        $detached = 0;

        foreach ($this->linkIdsFor($ids) as $linkId) {
            $action->execute($actor, $parent, $linkId);
            $detached++;
        }

        return $detached;
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>  $ids
     * @return array{attached: list<mixed>, detached: list<mixed>, updated: list<mixed>}
     */
    public function sync($ids, $detaching = true): array
    {
        throw $this->unsupported('sync');
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>  $ids
     * @return array{attached: list<mixed>, detached: list<mixed>, updated: list<mixed>}
     */
    public function syncWithoutDetaching($ids): array
    {
        throw $this->unsupported('syncWithoutDetaching');
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>  $ids
     * @return array{attached: list<mixed>, detached: list<mixed>}
     */
    public function toggle($ids, $touch = true): array
    {
        throw $this->unsupported('toggle');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): int
    {
        throw $this->unsupported('updateExistingPivot');
    }

    /**
     * @return list<string>
     */
    private function linkIdsFor(mixed $ids): array
    {
        $query = RecordLink::query()
            ->where('relationship_type_id', $this->descriptor->relationshipTypeId)
            ->where($this->descriptor->direction->ownColumn(), $this->getParent()->getKey());

        if ($ids !== null) {
            $query->whereIn($this->descriptor->direction->counterpartColumn(), $this->parseIds($ids));
        }

        /** @var list<string> $linkIds */
        $linkIds = $query->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        return $linkIds;
    }

    private function actor(): User
    {
        $actor = Auth::user();

        if (!$actor instanceof User) {
            throw new LogicException(
                'Relations can only be changed through the relation while a user is authenticated. '
                .'Use LinkRecordsAction in background contexts.',
            );
        }

        return $actor;
    }

    private function unsupported(string $method): LogicException
    {
        return new LogicException(
            "{$method}() is not available on a record relation. Use attach() and detach(), "
            .'or LinkRecordsAction and UnlinkRecordsAction directly.',
        );
    }
}
