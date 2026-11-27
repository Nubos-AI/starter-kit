<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Approvals\ApprovalCommandData;
use App\DTOs\Approvals\ApprovalProcessData;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Approvals\ApprovalProcessResource;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\User;
use App\Support\Approvals\ApprovalWorkloadQuery;
use App\Support\Approvals\TenantApprovalProcessResolver;
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
        private readonly ProcessStarterInterface $processes,
        private readonly TenantApprovalProcessResolver $approvalProcesses,
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
        $process = $this->approvalProcesses->resolve($user, $approval);

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
        $process = $this->approvalProcesses->resolve($user, $approval);
        $onBehalfOfId = $request->input('onBehalfOfId');

        $this->authorize('decide', [$process, is_string($onBehalfOfId) ? $onBehalfOfId : null]);

        $this->command($user, $process, 'decide', $request->only(['decision', 'reason', 'onBehalfOfId']));

        Inertia::flash('toast', [
            'type' => ToastType::Success->value,
            'message' => __('i18n.backend.http.controllers.approvals.approvals_controller.your_decision_has_been_recorded'),
        ]);

        return to_route('engine.approvals.index');
    }

    public function cancel(Request $request, string $approval): RedirectResponse
    {
        $user = $this->actingUser($request);
        $process = $this->approvalProcesses->resolve($user, $approval);

        $this->authorize('cancel', $process);

        $this->command($user, $process, 'cancel', $request->only(['reason']));

        Inertia::flash('toast', [
            'type' => ToastType::Success->value,
            'message' => __('i18n.backend.http.controllers.approvals.approvals_controller.the_approval_process_has_been_cancelled'),
        ]);

        return to_route('engine.approvals.index');
    }

    /**
     * @param  non-empty-string  $update
     * @param  array<string, mixed>  $input
     */
    private function command(User $user, ApprovalProcess $process, string $update, array $input): void
    {
        $tenantId = $process->tenant_id;
        $processId = (string) $process->getKey();
        $command = new ApprovalCommandData($tenantId, $processId, (string) $user->getKey(), $input);

        $this->processes->startWithUpdate((new ApprovalProcessData($tenantId, $processId))->processStart(), $update, [$command]);
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
