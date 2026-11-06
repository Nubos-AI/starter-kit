<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Http\Resources\FieldDefinitionResource;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\Segment;
use App\Models\User;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;

class ReportEditorPresenter
{
    public function __construct(
        private readonly SystemFilterFields $systemFields,
        private readonly ReportLinkedFieldEnumerator $linkedFields,
    ) {}

    /**
     * @param  EloquentCollection<int, ObjectType>  $objectTypes
     * @return array{
     *     objectTypeOptions: list<array{value: string, label: string}>,
     *     fieldsByType: array<string, list<array<string, mixed>>>,
     *     linkedFieldsByType: array<string, list<array<string, mixed>>>,
     *     segmentsByType: array<string, list<array{value: string, label: string}>>
     * }
     */
    public function payload(User $user, EloquentCollection $objectTypes): array
    {
        $reportable = $objectTypes
            ->filter(fn (ObjectType $type): bool => Gate::forUser($user)->allows('create', [Report::class, $type]))
            ->values();

        $fields = $this->filterableFields($reportable);
        $segments = $this->viewableSegments($user, $reportable);

        $options = [];
        $fieldsByType = [];
        $linkedFieldsByType = [];
        $segmentsByType = [];

        foreach ($reportable as $type) {
            $typeId = (string) $type->getKey();

            $options[] = ['value' => $typeId, 'label' => $type->name];

            $fieldsByType[$typeId] = array_values(array_merge(
                FieldDefinitionResource::collection($fields[$typeId] ?? new EloquentCollection)->resolve(),
                $this->systemFields->payload($this->systemFields->all($typeId)),
            ));

            $linkedFieldsByType[$typeId] = $this->linkedFields->payload($user, $type);

            $segmentsByType[$typeId] = array_map(
                static fn (Segment $segment): array => [
                    'value' => (string) $segment->getKey(),
                    'label' => $segment->name,
                ],
                $segments[$typeId] ?? [],
            );
        }

        return [
            'objectTypeOptions' => $options,
            'fieldsByType' => $fieldsByType,
            'linkedFieldsByType' => $linkedFieldsByType,
            'segmentsByType' => $segmentsByType,
        ];
    }

    /**
     * @param  EloquentCollection<int, ObjectType>  $objectTypes
     * @return array<string, EloquentCollection<int, FieldDefinition>>
     */
    private function filterableFields(EloquentCollection $objectTypes): array
    {
        $grouped = [];

        $fields = FieldDefinition::query()
            ->whereIn('object_type_id', $objectTypes->modelKeys())
            ->where('is_filterable', true)
            ->orderByRaw('list_position is null, list_position')
            ->orderBy('key')
            ->get()
            ->groupBy('object_type_id');

        foreach ($fields as $objectTypeId => $definitions) {
            $grouped[(string) $objectTypeId] = new EloquentCollection($definitions->all());
        }

        return $grouped;
    }

    /**
     * @param  EloquentCollection<int, ObjectType>  $objectTypes
     * @return array<string, list<Segment>>
     */
    private function viewableSegments(User $user, EloquentCollection $objectTypes): array
    {
        $grouped = [];

        $segments = Segment::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_system', false)
            ->whereNotNull('object_type_id')
            ->whereIn('object_type_id', $objectTypes->modelKeys())
            ->orderBy('name')
            ->get()
            ->filter(fn (Segment $segment): bool => $user->can('view', $segment));

        foreach ($segments as $segment) {
            $grouped[(string) $segment->object_type_id][] = $segment;
        }

        return $grouped;
    }
}
