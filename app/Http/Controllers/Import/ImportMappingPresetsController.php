<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Actions\Import\CreateImportMappingPresetAction;
use App\Actions\Import\DeleteImportMappingPresetAction;
use App\Actions\Import\UpdateImportMappingPresetAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ImportMappingPreset;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Import\ImportMappingValidator;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportMappingPresetsController extends Controller
{
    use RespondsWithValidationErrors;

    public function __construct(
        private readonly ImportMappingValidator $mappingValidator,
        private readonly CreateImportMappingPresetAction $createPreset,
        private readonly UpdateImportMappingPresetAction $updatePreset,
        private readonly DeleteImportMappingPresetAction $deletePreset,
    ) {}

    public function index(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $presets = ImportMappingPreset::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderBy('name')
            ->get()
            ->filter(fn (ImportMappingPreset $preset): bool => $this->stillValid($objectType, $user, $preset))
            ->map(fn (ImportMappingPreset $preset): array => $this->present($preset))
            ->values()
            ->all();

        return new JsonResponse(['data' => $presets]);
    }

    public function store(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        try {
            $preset = $this->createPreset->execute($objectType, $user, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(['data' => $this->present($preset)], 201);
    }

    public function update(Request $request, ObjectType $objectType, string $preset): JsonResponse
    {
        $user = $this->actingUser($request);

        $model = ImportMappingPreset::query()
            ->where('object_type_id', $objectType->getKey())
            ->whereKey($preset)
            ->firstOrFail();

        $this->authorize('update', $model);

        try {
            $this->updatePreset->execute($objectType, $user, $model, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(['data' => $this->present($model->refresh())]);
    }

    public function destroy(Request $request, ObjectType $objectType, string $preset): JsonResponse
    {
        $model = ImportMappingPreset::query()
            ->where('object_type_id', $objectType->getKey())
            ->whereKey($preset)
            ->firstOrFail();

        $this->authorize('delete', $model);

        $this->deletePreset->execute($model);

        return new JsonResponse(null, 204);
    }

    private function stillValid(ObjectType $objectType, User $user, ImportMappingPreset $preset): bool
    {
        try {
            $this->mappingValidator->validate($objectType, $user, $preset->mapping);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @return array{id: string, name: string, object_type_id: string, mapping: array<string, mixed>}
     */
    private function present(ImportMappingPreset $preset): array
    {
        return [
            'id' => (string) $preset->getKey(),
            'name' => $preset->name,
            'object_type_id' => $preset->object_type_id,
            'mapping' => $preset->mapping,
        ];
    }
}
