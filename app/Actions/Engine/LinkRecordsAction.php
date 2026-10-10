<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Engine\RelationCardinality;
use App\Enums\Timeline\RelationTimelineAction;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordLinkCardinalityGuard;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\RelationTimelineWriter;
use App\Traits\Engine\TranslatesUniqueViolations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class LinkRecordsAction
{
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly RelationTimelineWriter $timeline,
        private readonly RollupOwnerStarter $rollupStarter,
        private readonly RecordEndpointResolver $endpoints,
        private readonly RecordLinkCardinalityGuard $cardinalityGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(array $input): RecordLink
    {
        $validated = Validator::make(
            $input,
            [
                'relationship_type_id' => [
                    'required',
                    'string',
                    Rule::exists('relationship_types', 'id')
                        ->where('tenant_id', (string) TenantContext::currentId())
                        ->whereNull('deleted_at'),
                ],
                'from_record_id' => ['required', 'string'],
                'to_record_id' => ['required', 'string'],
                'position' => ['nullable', 'integer', 'min:0'],
            ]
        )->validate();

        $type = RelationshipType::query()->whereKey($validated['relationship_type_id'])->firstOrFail();
        $from = $this->endpoint($type->from_object_type_id, $validated['from_record_id'], 'from_record_id');
        $to = $this->endpoint($type->to_object_type_id, $validated['to_record_id'], 'to_record_id');

        $this->guardSameTenant($from, $to);
        $this->guardCardinality($type, $from, $to);

        try {
            return DB::transaction(function () use ($from, $to, $type, $validated): RecordLink {
                $link = RecordLink::query()->create([
                    'tenant_id' => $this->tenantOf($from, $to),
                    'relationship_type_id' => $type->getKey(),
                    'from_record_type' => $type->from_object_type_id,
                    'from_record_id' => $from->getKey(),
                    'to_record_type' => $type->to_object_type_id,
                    'to_record_id' => $to->getKey(),
                    'cardinality' => $type->cardinality,
                    'position' => (int) ($validated['position'] ?? 0),
                ]);

                $this->timeline->record($link, RelationTimelineAction::Linked);

                if ($from instanceof CustomRecord) {
                    $this->rollupStarter->startForRecords($from);
                }

                return $link;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'to_record_id' => __('i18n.backend.actions.engine.link_records_action.the_relationship_violates_the_configured_cardinality_or_already_exists'),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertRequiredRelationsSatisfied(CustomRecord $record): void
    {
        $missing = RelationshipType::query()
            ->where('from_object_type_id', $record->getAttribute('object_type_id'))
            ->where('is_required', true)
            ->get()
            ->reject(fn (RelationshipType $type): bool => RecordLink::query()
                ->where('relationship_type_id', $type->getKey())
                ->where('from_record_id', $record->getKey())
                ->exists());

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'relations' => $missing
                    ->map(fn (RelationshipType $type): string => __('i18n.backend.actions.engine.link_records_action.the_required_relationship_is_missing', ['value1' => $type->name]))
                    ->values()
                    ->all(),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function endpoint(string $objectTypeId, string $recordId, string $field): Model
    {
        $endpoint = $this->endpoints->find($objectTypeId, $recordId);

        if (!$endpoint instanceof Model) {
            throw ValidationException::withMessages([
                $field => __('i18n.backend.actions.engine.link_records_action.the_selected_record_does_not_belong_to_this_relationship'),
            ]);
        }

        return $endpoint;
    }

    private function tenantOf(Model $from, Model $to): ?string
    {
        foreach ([$from, $to] as $endpoint) {
            $tenantId = $endpoint->getAttribute('tenant_id');

            if (is_string($tenantId) && $tenantId !== '') {
                return $tenantId;
            }
        }

        return null;
    }

    private function guardSameTenant(Model $from, Model $to): void
    {
        $fromTenant = $from->getAttribute('tenant_id');
        $toTenant = $to->getAttribute('tenant_id');

        if (!is_string($fromTenant) || !is_string($toTenant) || $fromTenant === $toTenant) {
            return;
        }

        throw ValidationException::withMessages([
            'to_record_id' => __('i18n.backend.actions.engine.link_records_action.both_records_must_belong_to_the_same_tenant'),
        ]);
    }

    private function guardCardinality(RelationshipType $type, Model $from, Model $to): void
    {
        $isDuplicate = RecordLink::query()
            ->where('relationship_type_id', $type->getKey())
            ->where('from_record_id', $from->getKey())
            ->where('to_record_id', $to->getKey())
            ->exists();

        $existingParentId = $isDuplicate || $type->cardinality !== RelationCardinality::OneToMany
            ? null
            : RecordLink::query()
                ->where('relationship_type_id', $type->getKey())
                ->where('to_record_id', $to->getKey())
                ->value('from_record_id');

        $this->cardinalityGuard->assert(
            $type,
            $isDuplicate,
            $existingParentId === null ? null : (string) $existingParentId,
        );
    }
}
