<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Enums\Engine\ObjectTypeCapability;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\FieldDefinitionResource;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatableObjectTypesController extends Controller
{
    public function __construct(private readonly ObjectTypeCapabilityGuard $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        $types = ObjectType::query()
            ->with('fieldDefinitions')
            ->get()
            ->filter(fn (ObjectType $type): bool => $user->hasPermission("{$type->slug}.create")
                && $this->capabilities->supports($type, ObjectTypeCapability::Records))
            ->sortBy('name')
            ->values();

        return new JsonResponse(
            $types->map(fn (ObjectType $type): array => $this->creatablePayload($type))->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function creatablePayload(ObjectType $type): array
    {
        return [
            'id' => $type->id,
            'key' => $type->key,
            'slug' => $type->slug,
            'name' => $type->name,
            'fieldDefinitions' => FieldDefinitionResource::collection(
                $type->fieldDefinitions->sortBy('id')->values(),
            )->resolve(),
        ];
    }
}
