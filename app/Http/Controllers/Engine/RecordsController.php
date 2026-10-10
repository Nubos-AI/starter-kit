<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Enums\Watchers\WatcherSource;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\FieldDefinitionResource;
use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RecordMerge;
use App\Models\RecordWatcher;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\Aging\AgingEvaluator;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FieldGroupPresenter;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\ObjectTypePresenter;
use App\Support\Engine\RecordRouteResolver;
use App\Support\Modules\RecordExtensions;
use App\Support\Preferences\UserPreferenceResolver;
use App\Support\Trash\PurgeDeadline;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RecordsController extends Controller
{
    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly UserOptionPresenter $userOptions,
        private readonly RecordRouteResolver $recordRouteResolver,
        private readonly AgingEvaluator $agingEvaluator,
        private readonly FieldGroupPresenter $fieldGroupPresenter,
        private readonly UserPreferenceResolver $preferences,
        private readonly PurgeDeadline $purgeDeadline,
        private readonly ObjectTypePresenter $objectTypePresenter,
        private readonly RecordExtensions $extensions,
    ) {}

    /**
     * @return Builder<CustomRecord>
     */
    public function listQuery(Request $request, ObjectType $objectType): Builder
    {
        $query = CustomRecord::query()->ofType($objectType);

        $sortField = $this->resolveSortField($request, $objectType);

        if ($sortField instanceof FieldDefinition && $sortField->is_translatable) {
            $fallback = config('app.fallback_locale');
            $expression = $this->indexRegistry->localeSortExpression(
                $sortField->key,
                app()->getLocale(),
                is_string($fallback) ? $fallback : null,
            );

            $query->orderBy(DB::raw($expression))->orderBy('id');
        } elseif ($sortField instanceof FieldDefinition) {
            $plain = $this->indexRegistry->sortExpression($sortField);
            $query->orderBy(DB::raw($plain))->orderBy('id');
        } else {
            $query->orderBy('created_at')->orderBy('id');
        }

        return $query;
    }

    private function resolveSortField(Request $request, ObjectType $objectType): ?FieldDefinition
    {
        $requested = $request->query('sort');

        $sortable = $objectType->fieldDefinitions->filter(
            fn (FieldDefinition $field): bool => $field->is_sortable,
        );

        if (is_string($requested)) {
            $match = $sortable->firstWhere('key', $requested);

            if ($match instanceof FieldDefinition) {
                return $match;
            }
        }

        $translatable = $sortable->firstWhere('is_translatable', true);

        return $translatable instanceof FieldDefinition ? $translatable : $sortable->first();
    }

    public function create(Request $request, ObjectType $objectType): Response
    {
        $objectType->load('fieldDefinitions');

        $user = $this->actingUser($request);

        return Inertia::render('records/Form', [
            'mode' => 'create',
            'objectType' => $this->objectTypePayload($objectType),
            'fieldDefinitions' => $this->fieldDefinitions($objectType),
            'fieldGroups' => $this->fieldGroupPresenter->payload($objectType),
            'record' => null,
            ...$this->extensions->form($objectType, $user),
            'hasRelationships' => $this->hasRelationships($objectType),
            'preference' => $this->preferences->objectTypeSlice(
                $user,
                (string) $objectType->getKey(),
            ),
        ]);
    }

    public function edit(Request $request, string $record): Response
    {
        $user = $this->actingUser($request);
        $record = $this->resolveRecord($record);

        if ($record->trashed()) {
            throw new ModelNotFoundException;
        }

        if ($user->cannot('view', $record) || $user->cannot('update', $record)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.records_controller.you_may_not_edit_this_record'));
        }

        return Inertia::render('records/Form', array_merge(
            $this->recordPagePayload($record, $user),
            ['mode' => 'edit'],
        ));
    }

    public function show(Request $request, string $record): Response
    {
        $user = $this->actingUser($request);
        $record = $this->resolveRecord($record);

        if ($user->cannot('view', $record)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.records_controller.you_may_not_view_this_record'));
        }

        return Inertia::render('records/Form', array_merge(
            $this->recordPagePayload($record, $user),
            ['mode' => 'edit'],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPagePayload(CustomRecord $record, User $user): array
    {
        return [
            'objectType' => $this->objectTypePayload($record->objectType),
            'fieldDefinitions' => $this->fieldDefinitions($record->objectType),
            'fieldGroups' => $this->fieldGroupPresenter->payload($record->objectType),
            'record' => RecordResource::make($this->withAging($record, $user)),
            ...$this->extensions->form($record->objectType, $user, $record),
            'trashed' => $record->trashed(),
            'canUpdate' => !$record->trashed() && $user->can('update', $record),
            'canPurge' => $record->trashed() && $user->can('forceDelete', $record),
            'purgeOn' => $record->trashed() ? $this->purgeDeadline->for($record)?->toDateString() : null,
            'deletionReason' => $record->deletion_reason,
            'collaborators' => $this->collaboratorOptions($record),
            'hasRelationships' => $this->hasRelationships($record->objectType),
            'preference' => $this->preferences->objectTypeSlice(
                $user,
                (string) $record->objectType->getKey(),
            ),
            'undoableMerge' => $this->undoableMerge($record, $user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function undoableMerge(CustomRecord $record, User $user): ?array
    {
        if (!$user->can('merge', $record)) {
            return null;
        }

        $merge = RecordMerge::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $record->tenant_id)
            ->where('target_record_id', $record->getKey())
            ->whereNull('undone_at')
            ->orderByDesc('created_at')
            ->first();

        if (!$merge instanceof RecordMerge) {
            return null;
        }

        $windowDays = (int) config('engine.merge.undo_window_days');
        $mergedAt = $merge->created_at;
        $undoableUntil = $windowDays > 0 && $mergedAt !== null
            ? $mergedAt->copy()->addDays($windowDays)
            : null;

        if ($undoableUntil !== null && $undoableUntil->isPast()) {
            return null;
        }

        return [
            'id' => (string) $merge->getKey(),
            'sourceId' => $merge->source_record_id,
            'mergedAt' => $mergedAt?->toISOString(),
            'undoableUntil' => $undoableUntil?->toISOString(),
        ];
    }

    private function hasRelationships(ObjectType $objectType): bool
    {
        return RelationshipType::query()
            ->where('from_object_type_id', $objectType->getKey())
            ->orWhere('to_object_type_id', $objectType->getKey())
            ->exists();
    }

    private function withAging(CustomRecord $record, User $user): CustomRecord
    {
        $objectType = $record->objectType;

        $query = CustomRecord::query()->withTrashed()->whereKey($record->getKey());

        $expressions = $this->agingEvaluator->select(
            $query,
            $objectType,
            FieldVisibilityResolver::forRequest()->readableFields($user, $objectType),
        );

        if ($expressions === []) {
            return $record;
        }

        $rows = $query->get();

        $this->agingEvaluator->attachRuleNames($rows);

        return $rows->first() ?? $record;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collaboratorOptions(CustomRecord $record): array
    {
        $users = RecordWatcher::query()
            ->where('record_id', $record->getKey())
            ->where('source', WatcherSource::Collaborator)
            ->with('user')
            ->get()
            ->map(static fn (RecordWatcher $watcher): ?User => $watcher->user)
            ->filter()
            ->values();

        return $this->userOptions->presentMany($users);
    }

    private function resolveRecord(string $id): CustomRecord
    {
        return $this->recordRouteResolver->resolve($id, withTrashed: true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fieldDefinitions(ObjectType $objectType): array
    {
        $definitions = $objectType->fieldDefinitions->sortBy('id')->values();

        return FieldDefinitionResource::collection($definitions)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function objectTypePayload(ObjectType $objectType): array
    {
        return $this->objectTypePresenter->summary($objectType);
    }
}
