<?php

declare(strict_types=1);

namespace App\Http\Controllers\Promotion;

use App\Actions\Promotion\PreparePromotionRunAction;
use App\Actions\Promotion\RollbackPromotionRunAction;
use App\Actions\Promotion\SubmitPromotionRunAction;
use App\Actions\Promotion\UpdatePromotionSelectionAction;
use App\Contracts\Promotion\TenantPromotionSourceInterface;
use App\Exceptions\Approvals\RecordBoundCandidateCircleException;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\ConfigBundle\UndecidedConflictsException;
use App\Exceptions\ConfigBundle\UnresolvedDependenciesException;
use App\Exceptions\ConfigBundle\UnsupportedBundleSchemaVersionException;
use App\Exceptions\Promotion\CyclicArtifactDependencyException;
use App\Exceptions\Promotion\PromotionNotRollbackableException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Promotion\PromotionDiffResource;
use App\Http\Resources\Promotion\PromotionRunResource;
use App\Models\PromotionRun;
use App\Support\Promotion\PromotionPreviewBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PromotionRunsController extends Controller
{
    public function __construct(
        private readonly TenantPromotionSourceInterface $tenantSource,
        private readonly PreparePromotionRunAction $preparePromotionRun,
        private readonly UpdatePromotionSelectionAction $updatePromotionSelection,
        private readonly SubmitPromotionRunAction $submitPromotionRun,
        private readonly RollbackPromotionRunAction $rollbackPromotionRun,
        private readonly PromotionPreviewBuilder $previewBuilder,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PromotionRun::class);

        $runs = PromotionRun::query()
            ->with(['sourceTenant', 'triggeredBy'])
            ->orderByDesc('created_at')
            ->get();

        $createRefusal = $this->previewBuilder->creationRefusal($this->actingUser($request));

        return Inertia::render('promotion/Index', [
            'runs' => PromotionRunResource::collection($runs)->resolve($request),
            'can_create' => $createRefusal === null,
            'create_reason' => $createRefusal,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PromotionRun::class);

        return Inertia::render('promotion/Create', [
            'source_refusal' => $this->previewBuilder->creationRefusal($this->actingUser($request)),
            'source_error_key' => $this->tenantSource->validationKey(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PromotionRun::class);

        $run = $this->preparePromotionRun->execute($this->actingUser($request));

        return to_route('engine.promotions.show', ['promotionRun' => $run]);
    }

    public function show(Request $request, PromotionRun $promotionRun): Response
    {
        $this->authorize('view', $promotionRun);

        $preview = $this->previewBuilder->build($promotionRun);

        return Inertia::render(
            'promotion/Review',
            (new PromotionDiffResource($promotionRun))->withPreview($preview)->resolve($request),
        );
    }

    public function update(Request $request, PromotionRun $promotionRun): RedirectResponse
    {
        $this->authorize('update', $promotionRun);

        $user = $this->actingUser($request);

        $this->refusingOnFields(fn () => $this->updatePromotionSelection->execute($user, $promotionRun, $request->all()));

        return to_route('engine.promotions.show', ['promotionRun' => $promotionRun]);
    }

    public function submit(Request $request, PromotionRun $promotionRun): RedirectResponse
    {
        $this->authorize('submit', $promotionRun);

        $user = $this->actingUser($request);

        $this->refusingOnFields(fn () => $this->submitPromotionRun->execute($user, $promotionRun));

        return to_route('engine.promotions.show', ['promotionRun' => $promotionRun]);
    }

    public function rollback(Request $request, PromotionRun $promotionRun): RedirectResponse
    {
        $this->authorize('rollback', $promotionRun);

        $user = $this->actingUser($request);

        $this->refusingOnFields(fn () => $this->rollbackPromotionRun->execute($user, $promotionRun));

        return to_route('engine.promotions.index');
    }

    /**
     * @param  callable(): mixed  $operation
     *
     * @throws ValidationException
     */
    private function refusingOnFields(callable $operation): void
    {
        try {
            $operation();
        } catch (UndecidedConflictsException $exception) {
            throw ValidationException::withMessages(['conflicts' => $exception->getMessage()]);
        } catch (UnresolvedDependenciesException|CyclicArtifactDependencyException $exception) {
            throw ValidationException::withMessages(['selection' => $exception->getMessage()]);
        } catch (PromotionSourceUnavailableException|MalformedBundleException|UnsupportedBundleSchemaVersionException $exception) {
            throw ValidationException::withMessages(['source' => $exception->getMessage()]);
        } catch (RecordBoundCandidateCircleException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        } catch (PromotionNotRollbackableException $exception) {
            throw ValidationException::withMessages(['rollback' => $exception->getMessage()]);
        }
    }
}
