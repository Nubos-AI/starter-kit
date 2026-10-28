<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Actions\Approvals\CancelApprovalProcessAction;
use App\Actions\Approvals\DecideApprovalAction;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Approvals\ApprovalProcessResource;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\User;
use App\Support\Approvals\ApprovalWorkloadQuery;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalsController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkloadQuery $workloadQuery,
        private readonly DecideApprovalAction $decideApproval,
        private readonly CancelApprovalProcessAction $cancelApprovalProcess,
        private readonly UserOptionPresenter $userOptionPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        return Inertia::render('approvals/Index', [
            'approvals' => ApprovalProcessResource::collection(
                $this->workloadQuery->openFor($user),
            )->resolve($request),
        ]);
    }

    public function show(Request $request, string $approval): Response
    {
        $user = $this->actingUser($request);
        $process = $this->resolveProcess($user, $approval);

        $this->authorize('view', $process);

        $item = $this->workloadQuery->contextFor($user, $process);

        return Inertia::render('approvals/Decide', [
            'approval' => array_merge(ApprovalProcessResource::make($item)->resolve($request), [
                'history' => $this->historyOf($process),
                'can_decide' => $item->canDecide,
                'can_cancel' => Gate::forUser($user)->allows('cancel', $process),
                'decision_reason' => $item->decisionReason,
                'on_behalf_of_options' => $item->onBehalfOfOptions,
            ]),
        ]);
    }

    public function decide(Request $request, string $approval): RedirectResponse
    {
        $user = $this->actingUser($request);
        $process = $this->resolveProcess($user, $approval);

        $this->decideApproval->execute($user, $process, $request->all());

        Inertia::flash('toast', [
            'type' => ToastType::Success->value,
            'message' => __('i18n.backend.http.controllers.approvals.approvals_controller.your_decision_has_been_recorded'),
        ]);

        return to_route('engine.approvals.index');
    }

    public function cancel(Request $request, string $approval): RedirectResponse
    {
        $user = $this->actingUser($request);
        $process = $this->resolveProcess($user, $approval);

        $this->cancelApprovalProcess->execute($user, $process, $request->all());

        Inertia::flash('toast', [
            'type' => ToastType::Success->value,
            'message' => __('i18n.backend.http.controllers.approvals.approvals_controller.the_approval_process_has_been_cancelled'),
        ]);

        return to_route('engine.approvals.index');
    }

    private function resolveProcess(User $user, string $approval): ApprovalProcess
    {
        return ApprovalProcess::query()
            ->where('tenant_id', $user->tenant_id)
            ->with([
                'stages',
                'anchor',
                'record' => fn ($query) => $query->withTrashed()->with('objectType'),
                ...config('modules.approvals.eager_loads', []),
            ])
            ->whereKey($approval)
            ->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyOf(ApprovalProcess $process): array
    {
        return array_values($process->events()
            ->with(['actor', 'onBehalfOf'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ApprovalEvent $event): array => [
                'id' => (string) $event->getKey(),
                'type' => $event->type->value,
                'type_label' => $event->type->label(),
                'actor_label' => $event->actor instanceof User
                    ? $this->userOptionPresenter->label($event->actor)
                    : __('i18n.backend.http.controllers.approvals.approvals_controller.system'),
                'on_behalf_of_label' => $event->onBehalfOf instanceof User
                    ? $this->userOptionPresenter->label($event->onBehalfOf)
                    : null,
                'reason' => $event->reason,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])
            ->all());
    }
}
