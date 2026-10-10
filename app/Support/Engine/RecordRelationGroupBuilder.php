<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\RelationCardinality;
use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;

class RecordRelationGroupBuilder
{
    private int $candidateLimit = 50;

    public function __construct(
        private readonly RecordRelationResolver $relations,
        private readonly RecordTreeQuery $recordTreeQuery,
        private readonly RecordTitleResolver $titleResolver,
        private readonly RecordSearchPredicate $searchPredicate,
        private readonly RecordEndpointResolver $endpoints,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function build(CustomRecord $record, User $user): array
    {
        $record->loadMissing('objectType');

        return array_values(
            $this->relations->typesOf($record->objectType)
                ->flatMap(fn (RelationshipType $type): array => $this->groupsFor($record, $type, $user))
                ->all(),
        );
    }

    /**
     * @return array{entries: list<array{linkId: string, recordId: string, recordNumber: string|null, label: string}>, total: int, lastRow: int|null}
     *
     * @throws ValidationException
     */
    public function entriesPage(
        CustomRecord $record,
        User $user,
        string $relationshipTypeId,
        RelationDirection $direction,
        int $offset,
        int $limit,
        ?string $search = null,
    ): array {
        $type = $this->typeOf($record, $relationshipTypeId);

        $this->relations->assertMayViewCounterpart($user, $type, $direction);

        $rows = $this->entryRows($record, $type, $direction, $user, $offset, $limit, $search);
        $total = $this->searchedLinks($record, $type, $direction, $user, $search)->count();

        return [
            'entries' => $rows,
            'total' => $total,
            'lastRow' => $offset + count($rows) >= $total ? $total : null,
        ];
    }

    /**
     * @return array{candidates: list<array{id: string, label: string}>, hasMore: bool}
     *
     * @throws ValidationException
     */
    public function candidatesPage(
        CustomRecord $record,
        User $user,
        string $relationshipTypeId,
        RelationDirection $direction,
        int $offset,
        int $limit,
        ?string $search = null,
    ): array {
        $type = $this->typeOf($record, $relationshipTypeId);
        $this->relations->assertMayViewCounterpart($user, $type, $direction);
        $query = $this->candidateQuery($record, $type, $direction, $user, $search);

        if ($query === null) {
            return ['candidates' => [], 'hasMore' => false];
        }

        $rows = $this->candidateRows(
            $query,
            $user,
            $this->relations->counterpartObjectTypeId($type, $direction),
            $offset,
            $limit + 1,
        );

        return [
            'candidates' => array_slice($rows, 0, $limit),
            'hasMore' => count($rows) > $limit,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function typeOf(CustomRecord $record, string $relationshipTypeId): RelationshipType
    {
        $record->loadMissing('objectType');

        $type = $this->relations->typesOf($record->objectType)
            ->first(fn (RelationshipType $candidate): bool => (string) $candidate->getKey() === $relationshipTypeId);

        if (!$type instanceof RelationshipType) {
            throw ValidationException::withMessages([
                'relationshipTypeId' => __('i18n.backend.support.engine.record_relation_group_builder.this_relationship_does_not_belong_to_this_object_type'),
            ]);
        }

        return $type;
    }

    /**
     * @return Builder<RecordLink>
     */
    private function searchedLinks(
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
        User $user,
        ?string $search,
    ): Builder {
        $counterpartTypeId = $this->relations->counterpartObjectTypeId($type, $direction);
        $visibleCounterparts = $this->endpoints->newQuery($counterpartTypeId);
        $visibleCounterparts->select($visibleCounterparts->getModel()->getQualifiedKeyName());

        $links = $this->linksOf($record, $type, $direction)
            ->whereIn($direction->counterpartColumn(), $visibleCounterparts);

        $matching = CustomRecord::query()
            ->ofType($counterpartTypeId)
            ->select('id');

        if (!$this->searchPredicate->applyIdentity($matching, $user, $counterpartTypeId, $search)) {
            return $links;
        }

        return $links->whereIn($direction->counterpartColumn(), $matching);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupsFor(CustomRecord $record, RelationshipType $type, User $user): array
    {
        $objectTypeId = $record->object_type_id;
        $directions = [];

        if ($type->from_object_type_id === $objectTypeId) {
            $directions[] = RelationDirection::Outgoing;
        }

        if ($type->to_object_type_id === $objectTypeId) {
            $directions[] = RelationDirection::Incoming;
        }

        if ($type->is_hierarchy) {
            $directions = array_reverse($directions);
        }

        return array_map(
            fn (RelationDirection $direction): array => $this->group($record, $type, $direction, $user),
            array_values(array_filter(
                $directions,
                fn (RelationDirection $direction): bool => $this->relations->mayViewCounterpart($user, $type, $direction),
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function group(CustomRecord $record, RelationshipType $type, RelationDirection $direction, User $user): array
    {
        $entries = $this->entriesFor($record, $type, $direction, $user);
        $candidates = $this->candidatesFor($record, $type, $direction, $user);

        return [
            'relationshipTypeId' => (string) $type->getKey(),
            'direction' => $direction->value,
            'isHierarchy' => $type->is_hierarchy,
            'objectTypeName' => $this->counterpartObjectTypeName($type, $direction),
            'roleLabel' => $direction->isOutgoing() ? $type->name : $type->inverse_name,
            'acceptsOne' => $this->acceptsOne($type, $direction),
            'canEdit' => $this->canEdit($user, $record, $type),
            'candidates' => $candidates['rows'],
            'candidatesTruncated' => $candidates['truncated'],
            'entries' => $entries['rows'],
            'entriesTotal' => $entries['total'],
            'entriesTruncated' => $entries['truncated'],
        ];
    }

    /**
     * @return array{rows: list<array{linkId: string, recordId: string, recordNumber: string|null, label: string}>, total: int, truncated: bool}
     */
    private function entriesFor(
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
        User $user,
    ): array {
        $limit = $this->maxEntries();

        $total = $this->searchedLinks($record, $type, $direction, $user, null)->count();
        $rows = $this->entryRows($record, $type, $direction, $user, 0, $limit);

        return ['rows' => $rows, 'total' => $total, 'truncated' => $total > $limit];
    }

    /**
     * @return list<array{linkId: string, recordId: string, recordNumber: string|null, label: string}>
     */
    private function entryRows(
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
        User $user,
        int $offset,
        int $limit,
        ?string $search = null,
    ): array {
        $counterpartColumn = $direction->counterpartColumn();

        $links = $this->searchedLinks($record, $type, $direction, $user, $search)
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $counterpartTypeId = $this->relations->counterpartObjectTypeId($type, $direction);

        /** @var list<string> $counterpartIds */
        $counterpartIds = $links->pluck($counterpartColumn)
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $counterparts = $this->endpoints->findMany($counterpartTypeId, $counterpartIds);

        return array_values(array_filter(array_map(
            function (RecordLink $link) use ($counterparts, $counterpartColumn, $counterpartTypeId, $user): ?array {
                $id = (string) $link->{$counterpartColumn};
                $counterpart = $counterparts->get($id);

                if (!$counterpart instanceof Model) {
                    return null;
                }

                return [
                    'linkId' => (string) $link->getKey(),
                    'recordId' => $id,
                    'recordNumber' => $counterpart instanceof CustomRecord ? $counterpart->record_number : null,
                    'label' => $this->entryLabel($user, $counterpartTypeId, $counterpart),
                ];
            },
            $links->all(),
        )));
    }

    /**
     * @return Builder<RecordLink>
     */
    private function linksOf(CustomRecord $record, RelationshipType $type, RelationDirection $direction): Builder
    {
        return RecordLink::query()
            ->where('relationship_type_id', $type->getKey())
            ->where($direction->ownColumn(), $record->getKey());
    }

    /**
     * @return array{rows: list<array{id: string, label: string}>, truncated: bool}
     */
    private function candidatesFor(
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
        User $user,
    ): array {
        $query = $this->candidateQuery($record, $type, $direction, $user, null);

        if ($query === null) {
            return ['rows' => [], 'truncated' => false];
        }

        $rows = $this->candidateRows(
            $query,
            $user,
            $this->relations->counterpartObjectTypeId($type, $direction),
            0,
            $this->candidateLimit + 1,
        );

        return [
            'rows' => array_slice($rows, 0, $this->candidateLimit),
            'truncated' => count($rows) > $this->candidateLimit,
        ];
    }

    /**
     * @return Builder<covariant Model>|null
     */
    private function candidateQuery(
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
        User $user,
        ?string $search,
    ): ?Builder {
        if (!$this->canEdit($user, $record, $type)) {
            return null;
        }

        $counterpartTypeId = $this->relations->counterpartObjectTypeId($type, $direction);
        $query = $this->endpoints->newQuery($counterpartTypeId)->whereKeyNot($record->getKey());

        if ($query->getModel() instanceof CustomRecord) {
            $query->where('tenant_id', $record->tenant_id);
        }

        $this->excludeLinkedCounterparts($query, $record, $type, $direction);
        $this->excludeHierarchyRelatives($query, $record, $type, $direction);
        $this->excludeTakenTargets($query, $record, $type, $direction);
        $this->applyCandidateSearch($query, $user, $counterpartTypeId, $search);

        return $query;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyCandidateSearch(Builder $query, User $user, string $counterpartTypeId, ?string $search): void
    {
        if ($query->getModel() instanceof CustomRecord) {
            /** @var Builder<CustomRecord> $query */
            $this->searchPredicate->applyIdentity($query, $user, $counterpartTypeId, $search);

            return;
        }

        if ($search === null || $search === '') {
            return;
        }

        $titleColumn = $this->endpoints->titleColumn($counterpartTypeId);

        if ($titleColumn !== null) {
            $query->whereLike($titleColumn, '%'.$search.'%', false);
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return list<array{id: string, label: string}>
     */
    private function candidateRows(Builder $query, User $user, string $counterpartTypeId, int $offset, int $limit): array
    {
        return array_values(
            $query
                ->orderBy('created_at')
                ->orderBy('id')
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(fn (Model $candidate): array => [
                    'id' => (string) $candidate->getKey(),
                    'label' => $this->entryLabel($user, $counterpartTypeId, $candidate),
                ])
                ->all(),
        );
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function excludeLinkedCounterparts(
        Builder $query,
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
    ): void {
        $ownColumn = $direction->ownColumn();
        $counterpartColumn = $direction->counterpartColumn();
        $candidateKey = $this->qualifiedCandidateKey($query);

        $query->whereNotExists(
            function (QueryBuilder $linked) use ($record, $type, $ownColumn, $counterpartColumn, $candidateKey): void {
                $linked->selectRaw('1')
                    ->from('record_links')
                    ->where('record_links.tenant_id', $record->tenant_id)
                    ->where('record_links.relationship_type_id', $type->getKey())
                    ->where("record_links.{$ownColumn}", $record->getKey())
                    ->whereColumn("record_links.{$counterpartColumn}", $candidateKey);
            },
        );
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function excludeTakenTargets(
        Builder $query,
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
    ): void {
        if (!$direction->isOutgoing() || $type->cardinality !== RelationCardinality::OneToMany) {
            return;
        }

        $candidateKey = $this->qualifiedCandidateKey($query);

        $query->whereNotExists(
            function (QueryBuilder $taken) use ($record, $type, $candidateKey): void {
                $taken->selectRaw('1')
                    ->from('record_links')
                    ->where('record_links.tenant_id', $record->tenant_id)
                    ->where('record_links.relationship_type_id', $type->getKey())
                    ->whereColumn('record_links.to_record_id', $candidateKey);
            },
        );
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function qualifiedCandidateKey(Builder $query): string
    {
        $model = $query->getModel();

        return $model->getTable().'.'.$model->getKeyName();
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function excludeHierarchyRelatives(
        Builder $query,
        CustomRecord $record,
        RelationshipType $type,
        RelationDirection $direction,
    ): void {
        if (!$type->is_hierarchy) {
            return;
        }

        $relatives = $direction->isOutgoing()
            ? $this->recordTreeQuery->ancestorsOf(
                $record->tenant_id,
                (string) $type->getKey(),
                (string) $record->getKey(),
            )
            : $this->recordTreeQuery->descendantsOf(
                $record->tenant_id,
                (string) $type->getKey(),
                (string) $record->getKey(),
            );

        $ids = array_map(
            static fn (object $relative): string => $relative->recordId,
            $relatives->nodes,
        );

        if ($ids === []) {
            return;
        }

        $query->whereKeyNot($ids);
    }

    private function acceptsOne(RelationshipType $type, RelationDirection $direction): bool
    {
        return !$direction->isOutgoing()
            && $type->cardinality === RelationCardinality::OneToMany;
    }

    private function canEdit(User $user, CustomRecord $record, RelationshipType $type): bool
    {
        if ($user->cannot('update', $record)) {
            return false;
        }

        if (!$type->is_hierarchy) {
            return true;
        }

        return $this->relations->mayReparent($user, $record);
    }

    private function counterpartObjectTypeName(RelationshipType $type, RelationDirection $direction): string
    {
        $objectType = ObjectType::query()
            ->whereKey($this->relations->counterpartObjectTypeId($type, $direction))
            ->first();

        return $objectType instanceof ObjectType ? $objectType->name : '';
    }

    private function entryLabel(User $user, string $counterpartTypeId, Model $record): string
    {
        if (!$record instanceof CustomRecord) {
            return $this->endpoints->titleOf($counterpartTypeId, $record);
        }

        $title = $this->titleResolver->titleFor($user, $record);
        $number = $record->record_number;

        if ($number === null || $number === '') {
            return $title;
        }

        return $title === '' || $title === $number ? $number : "{$number} · {$title}";
    }

    private function maxEntries(): int
    {
        return (int) config('engine.relations.max_entries');
    }
}
