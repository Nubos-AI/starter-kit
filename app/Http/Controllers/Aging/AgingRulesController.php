<?php

declare(strict_types=1);

namespace App\Http\Controllers\Aging;

use App\Actions\Aging\BulkDeleteAgingRulesAction;
use App\Actions\Aging\CreateAgingRuleAction;
use App\Actions\Aging\DeleteAgingRuleAction;
use App\Actions\Aging\UpdateAgingRuleAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Aging\AgingRuleResource;
use App\Models\AgingRule;
use App\Models\ObjectType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AgingRulesController extends Controller
{
    public function __construct(
        private readonly CreateAgingRuleAction $createAgingRule,
        private readonly UpdateAgingRuleAction $updateAgingRule,
        private readonly DeleteAgingRuleAction $deleteAgingRule,
        private readonly BulkDeleteAgingRulesAction $bulkDeleteAgingRules,
    ) {}

    public function index(ObjectType $objectType): JsonResponse
    {
        $this->authorize('viewAny', [AgingRule::class, $objectType]);

        $agingRules = AgingRule::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderBy('created_at')
            ->get();

        return AgingRuleResource::collection($agingRules)->response();
    }

    public function store(Request $request, ObjectType $objectType): RedirectResponse
    {
        $this->authorize('create', [AgingRule::class, $objectType]);

        $this->createAgingRule->execute($objectType, $request->all());

        return $this->redirectToEdit($objectType);
    }

    public function update(Request $request, ObjectType $objectType, AgingRule $agingRule): RedirectResponse
    {
        $this->authorize('update', $agingRule);

        $this->guardBelongsToType($agingRule, $objectType);

        $this->updateAgingRule->execute($agingRule, $request->all());

        return $this->redirectToEdit($objectType);
    }

    public function destroy(ObjectType $objectType, AgingRule $agingRule): RedirectResponse
    {
        $this->authorize('delete', $agingRule);

        $this->guardBelongsToType($agingRule, $objectType);

        $this->deleteAgingRule->execute($agingRule);

        return $this->redirectToEdit($objectType);
    }

    public function bulkDestroy(Request $request, ObjectType $objectType): RedirectResponse
    {
        $this->authorize('deleteAny', [AgingRule::class, $objectType]);

        $this->bulkDeleteAgingRules->execute($this->actingUser($request), $request->all(), $objectType);

        return $this->redirectToEdit($objectType);
    }

    private function guardBelongsToType(AgingRule $agingRule, ObjectType $objectType): void
    {
        if ($agingRule->object_type_id !== $objectType->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function redirectToEdit(ObjectType $objectType): RedirectResponse
    {
        return to_route('engine.object-types.edit.aging-rules', ['objectType' => $objectType->slug]);
    }
}
