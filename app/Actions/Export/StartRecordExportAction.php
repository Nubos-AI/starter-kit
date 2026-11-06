<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Enums\Export\ExportFormat;
use App\Enums\Export\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StartRecordExportAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ObjectType $objectType, User $user, array $input): ExportJob
    {
        $validated = Validator::make(
            $input,
            [
                'format' => ['required', 'string', Rule::enum(ExportFormat::class)],
                'scope' => ['required', 'array'],
                'scope.mode' => ['required', 'string', 'in:view,segment,whole-type'],
                'scope.segmentId' => ['nullable', 'string'],
                'scope.filterModel' => ['nullable', 'array'],
                'scope.search' => ['nullable', 'string', 'max:255'],
                'scope.sortModel' => ['nullable', 'array'],
                'fields' => ['nullable', 'array'],
                'fields.*' => ['string'],
            ]
        )->validate();

        /** @var array<string, mixed> $scope */
        $scope = $validated['scope'];

        /** @var list<string> $fields */
        $fields = array_values(array_filter(
            is_array($validated['fields'] ?? null) ? $validated['fields'] : [],
            static fn (mixed $key): bool => is_string($key),
        ));

        return ExportJob::query()->create([
            'tenant_id' => (string) $user->tenant_id,
            'object_type_id' => $objectType->getKey(),
            'user_id' => (string) $user->getKey(),
            'status' => ExportJobStatus::Running,
            'format' => ExportFormat::from((string) $validated['format']),
            'scope' => $scope,
            'fields' => $fields,
            'started_at' => now(),
        ]);
    }
}
