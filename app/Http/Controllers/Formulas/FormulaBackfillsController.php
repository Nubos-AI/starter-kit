<?php

declare(strict_types=1);

namespace App\Http\Controllers\Formulas;

use App\Actions\Formulas\CancelFormulaBackfillAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Formulas\FormulaBackfillRunResource;
use App\Models\FormulaBackfillRun;
use App\Models\ObjectType;
use Illuminate\Http\JsonResponse;

class FormulaBackfillsController extends Controller
{
    public function __construct(private readonly CancelFormulaBackfillAction $cancelAction) {}

    public function show(ObjectType $objectType): JsonResponse
    {
        $run = FormulaBackfillRun::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return new JsonResponse(['run' => $this->runPayload($run)]);
    }

    public function cancel(FormulaBackfillRun $backfillRun): JsonResponse
    {
        $this->cancelAction->execute($backfillRun);

        return new JsonResponse(['run' => $this->runPayload($backfillRun->refresh())]);
    }

    private function runPayload(?FormulaBackfillRun $run): ?FormulaBackfillRunResource
    {
        return $run === null ? null : new FormulaBackfillRunResource($run);
    }
}
