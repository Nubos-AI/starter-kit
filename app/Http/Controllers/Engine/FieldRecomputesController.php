<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\RecomputeFieldAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Formulas\FormulaBackfillRunResource;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\FieldOwnershipGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class FieldRecomputesController extends Controller
{
    public function __construct(
        private readonly RecomputeFieldAction $recomputeField,
        private readonly FieldOwnershipGuard $ownershipGuard,
    ) {}

    /**
     * @throws ValidationException
     */
    public function store(ObjectType $objectType, FieldDefinition $field): JsonResponse
    {
        $this->ownershipGuard->assertBelongsTo($field, $objectType);

        $run = $this->recomputeField->execute($field);

        return new JsonResponse([
            'run' => $run === null ? null : new FormulaBackfillRunResource($run),
        ]);
    }
}
