<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use App\Actions\Export\CreateExportFieldPresetAction;
use App\Actions\Export\DeleteExportFieldPresetAction;
use App\Actions\Export\UpdateExportFieldPresetAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ExportFieldPreset;
use App\Models\ObjectType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportFieldPresetsController extends Controller
{
    public function __construct(
        private readonly CreateExportFieldPresetAction $createPreset,
        private readonly UpdateExportFieldPresetAction $updatePreset,
        private readonly DeleteExportFieldPresetAction $deletePreset,
    ) {}

    public function index(Request $request, ObjectType $objectType): JsonResponse
    {
        $presets = ExportFieldPreset::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderBy('name')
            ->get()
            ->map(fn (ExportFieldPreset $preset): array => $this->present($preset))
            ->values()
            ->all();

        return new JsonResponse(['data' => $presets]);
    }

    public function store(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $preset = $this->createPreset->execute($objectType, $user, $request->all());

        return new JsonResponse(['data' => $this->present($preset)], 201);
    }

    public function update(Request $request, ObjectType $objectType, string $preset): JsonResponse
    {
        $model = ExportFieldPreset::query()
            ->where('object_type_id', $objectType->getKey())
            ->whereKey($preset)
            ->firstOrFail();

        $this->authorize('update', $model);

        $this->updatePreset->execute($model, $request->all());

        return new JsonResponse(['data' => $this->present($model->refresh())]);
    }

    public function destroy(Request $request, ObjectType $objectType, string $preset): JsonResponse
    {
        $model = ExportFieldPreset::query()
            ->where('object_type_id', $objectType->getKey())
            ->whereKey($preset)
            ->firstOrFail();

        $this->authorize('delete', $model);

        $this->deletePreset->execute($model);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array{id: string, name: string, object_type_id: string, fields: array<int, string>}
     */
    private function present(ExportFieldPreset $preset): array
    {
        return [
            'id' => (string) $preset->getKey(),
            'name' => $preset->name,
            'object_type_id' => $preset->object_type_id,
            'fields' => $preset->fields,
        ];
    }
}
