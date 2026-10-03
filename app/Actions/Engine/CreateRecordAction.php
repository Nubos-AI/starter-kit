<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\DefaultValueResolver;
use App\Support\Engine\RecordNumberFormatter;
use App\Support\Engine\RecordValidator;
use App\Support\Modules\RecordExtensions;
use App\Support\Tenancy\TenantContext;
use App\Traits\Engine\GuardsOwnerAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateRecordAction
{
    use GuardsOwnerAssignment;

    public function __construct(
        private readonly RecordNumberFormatter $recordNumberFormatter,
        private readonly RecordValidator $recordValidator,
        private readonly DefaultValueResolver $defaultValueResolver,
        private readonly AuditRecorder $auditRecorder,
        private readonly ComputedFieldWriter $computedFieldWriter,
        private readonly RecordExtensions $extensions,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(array $input, bool $isUserInput = false): CustomRecord
    {
        return $this->create($input, audited: true, isUserInput: $isUserInput);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function executeWithoutProtocol(array $input): CustomRecord
    {
        return $this->create($input, audited: false, isUserInput: false);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    private function create(array $input, bool $audited, bool $isUserInput): CustomRecord
    {
        $validated = Validator::make(
            $input,
            [
                'object_type_id' => [
                    'required',
                    'string',
                    Rule::exists('object_types', 'id')->where('tenant_id', (string) TenantContext::currentId()),
                ],
                'tenant_id' => ['nullable', 'string'],
                'team_id' => ['nullable', 'string'],
                'owner_id' => ['nullable', 'string', $this->ownerRule((string) TenantContext::currentId())],
                'external_reference_id' => ['nullable', 'string'],
                'data' => ['nullable', 'array'],
            ]
        )->validate();

        $tenantId = $this->resolveTenantId($validated);

        if ($isUserInput) {
            $this->guardForeignOwner($validated['owner_id'] ?? null);
        }

        $objectType = ObjectType::query()->whereKey($validated['object_type_id'])->firstOrFail();
        $inputData = is_array($validated['data'] ?? null) ? $validated['data'] : [];
        $this->guardWritableFields($objectType, $inputData);
        $fields = $objectType->fieldDefinitions()->get();
        $data = $this->defaultValueResolver->applyStaticDefaults($fields, $inputData);

        $this->recordValidator->validate($objectType, $data, $tenantId);

        $recordNumber = $this->recordNumberFormatter->next($objectType, $tenantId);
        $data = $this->defaultValueResolver->applySequenceDefaults($fields, $data, $recordNumber);
        $attributes = $this->extensions->attributes($objectType, $input);

        return DB::transaction(function () use ($validated, $tenantId, $objectType, $recordNumber, $attributes, $data, $audited): CustomRecord {
            $record = new CustomRecord;
            $record->forceFill($attributes);
            $record->fill([
                'tenant_id' => $tenantId,
                'team_id' => $validated['team_id'] ?? null,
                'owner_id' => $validated['owner_id'] ?? Auth::id(),
                'object_type_id' => $objectType->getKey(),
                'record_number' => $recordNumber,
                'external_reference_id' => $validated['external_reference_id'] ?? null,
                'version' => 1,
                'data' => $data === [] ? null : $data,
            ]);

            $record->save();
            $this->extensions->saved($record);

            if ($audited) {
                $this->auditRecorder->record($record, [], $this->auditState($record), 1);
            }

            $this->computedFieldWriter->materialize($record);

            return $record;
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function guardForeignOwner(?string $ownerId): void
    {
        $user = Auth::user();

        if ($ownerId === null || !$user instanceof User || $ownerId === (string) $user->getKey()) {
            return;
        }

        $this->guardOwnerAssignment($user);
    }

    /**
     * @param  array<string, mixed>  $inputData
     */
    private function guardWritableFields(ObjectType $objectType, array $inputData): void
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            return;
        }

        $forbiddenKeys = FieldVisibilityResolver::forRequest()
            ->forbiddenWriteFieldKeys($user, (string) $objectType->getKey());

        $attempted = array_intersect(array_keys($inputData), $forbiddenKeys);

        if ($attempted !== []) {
            throw new AuthorizationException(
                __('i18n.backend.actions.engine.create_record_action.you_lack_write_permission_for_these_fields').implode(', ', $attempted).'.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditState(CustomRecord $record): array
    {
        return array_merge($record->data ?? [], [
            'owner_id' => $record->owner_id,
            'external_reference_id' => $record->external_reference_id,
            ...$this->extensions->auditAttributes($record),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveTenantId(array $validated): string
    {
        if (app()->bound('current_tenant')) {
            $boundTenantId = (string) app('current_tenant')->getKey();

            if (!empty($validated['tenant_id']) && (string) $validated['tenant_id'] !== $boundTenantId) {
                throw ValidationException::withMessages([
                    'tenant_id' => __('i18n.backend.actions.engine.create_record_action.writing_across_tenant_boundaries_is_not_allowed'),
                ]);
            }

            return $boundTenantId;
        }

        if (!empty($validated['tenant_id'])) {
            return (string) $validated['tenant_id'];
        }

        throw ValidationException::withMessages([
            'tenant_id' => __('i18n.backend.actions.engine.create_record_action.no_tenant_is_bound_a_record_requires_a_tenant'),
        ]);
    }
}
