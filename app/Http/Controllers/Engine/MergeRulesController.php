<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\BulkDeleteMergeRulesAction;
use App\Actions\Engine\CreateMergeRuleAction;
use App\Actions\Engine\DeleteMergeRuleAction;
use App\Actions\Engine\UpdateMergeRuleAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Engine\MergeRuleResource;
use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Support\Engine\MergeRuleEditorOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MergeRulesController extends Controller
{
    public function __construct(
        private readonly CreateMergeRuleAction $createMergeRule,
        private readonly UpdateMergeRuleAction $updateMergeRule,
        private readonly DeleteMergeRuleAction $deleteMergeRule,
        private readonly BulkDeleteMergeRulesAction $bulkDeleteMergeRules,
        private readonly MergeRuleEditorOptions $editorOptions,
    ) {}

    public function create(Request $request, ObjectType $objectType): Response
    {
        $this->authorize('create', [MergeRule::class, $objectType]);

        return $this->renderForm($request, $objectType, null);
    }

    public function edit(Request $request, ObjectType $objectType, MergeRule $mergeRule): Response
    {
        $this->authorize('update', $mergeRule);

        $this->guardBelongsToType($mergeRule, $objectType);

        return $this->renderForm($request, $objectType, $mergeRule);
    }

    public function index(ObjectType $objectType): JsonResponse
    {
        $this->authorize('viewAny', [MergeRule::class, $objectType]);

        $mergeRules = MergeRule::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        return MergeRuleResource::collection($mergeRules)->response();
    }

    public function store(Request $request, ObjectType $objectType): RedirectResponse
    {
        $this->authorize('create', [MergeRule::class, $objectType]);

        $this->createMergeRule->execute($objectType, $request->all());

        return $this->redirectToEdit($objectType);
    }

    public function update(Request $request, ObjectType $objectType, MergeRule $mergeRule): RedirectResponse
    {
        $this->authorize('update', $mergeRule);

        $this->guardBelongsToType($mergeRule, $objectType);

        $this->updateMergeRule->execute($mergeRule, $request->all());

        return $this->redirectToEdit($objectType);
    }

    public function destroy(ObjectType $objectType, MergeRule $mergeRule): RedirectResponse
    {
        $this->authorize('delete', $mergeRule);

        $this->guardBelongsToType($mergeRule, $objectType);

        $this->deleteMergeRule->execute($mergeRule);

        return $this->redirectToEdit($objectType);
    }

    public function bulkDestroy(Request $request, ObjectType $objectType): RedirectResponse
    {
        $this->authorize('deleteAny', [MergeRule::class, $objectType]);

        $this->bulkDeleteMergeRules->execute($this->actingUser($request), $request->all(), $objectType);

        return $this->redirectToEdit($objectType);
    }

    private function renderForm(Request $request, ObjectType $objectType, ?MergeRule $mergeRule): Response
    {
        $user = $this->actingUser($request);

        return Inertia::render('objectTypes/mergeRules/Form', [
            'objectType' => [
                'slug' => $objectType->slug,
                'name' => $objectType->name,
            ],
            'mergeRule' => $mergeRule instanceof MergeRule
                ? (new MergeRuleResource($mergeRule))->resolve($request)
                : null,
            'activeRuleCount' => MergeRule::query()
                ->where('object_type_id', $objectType->getKey())
                ->where('is_active', true)
                ->when(
                    $mergeRule instanceof MergeRule,
                    fn ($query) => $query->whereKeyNot($mergeRule?->getKey()),
                )
                ->count(),
            ...$this->editorOptions->all($user, $objectType),
        ]);
    }

    private function guardBelongsToType(MergeRule $mergeRule, ObjectType $objectType): void
    {
        if ($mergeRule->object_type_id !== $objectType->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function redirectToEdit(ObjectType $objectType): RedirectResponse
    {
        return to_route('engine.object-types.edit.merge-rules', ['objectType' => $objectType->slug]);
    }
}
