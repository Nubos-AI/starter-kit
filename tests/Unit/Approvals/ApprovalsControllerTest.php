<?php

declare(strict_types=1);

use App\Actions\Approvals\CancelApprovalProcessAction;
use App\Actions\Approvals\DecideApprovalAction;
use App\Actions\Approvals\DescribeApprovalProcessAction;
use App\Actions\Approvals\EscalateApprovalStageAction;
use App\Actions\Approvals\NotifyApprovalDecisionAction;
use App\Actions\Approvals\NotifyApprovalEscalationAction;
use App\Actions\Approvals\NotifyApprovalStageStartedAction;
use App\Activities\Approvals\ApprovalProcessActivity;
use App\Contracts\Approvals\ApprovalProcessWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Approvals\ApprovalCommandData;
use App\DTOs\Temporal\ProcessStartData;
use App\Http\Controllers\Approvals\ApprovalsController;
use App\Models\ApprovalProcess;
use App\Models\User;
use App\Support\Approvals\ApprovalWorkloadQuery;
use App\Support\Approvals\TenantApprovalProcessResolver;
use App\Support\Temporal\ActivityFailures;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'approver');
    $this->process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('approval-process'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->processes = Mockery::mock(ProcessStarterInterface::class);

    $resolver = Mockery::mock(TenantApprovalProcessResolver::class);
    $resolver->shouldReceive('resolve')->with($this->actor, (string) $this->process->getKey())->andReturn($this->process);

    $this->request = fn (array $input): Request => tap(Request::create('/probe', 'POST', $input), fn (Request $request) => $request->setUserResolver(fn (): User => $this->actor));

    $this->controller = new ApprovalsController(
        Mockery::mock(ApprovalWorkloadQuery::class),
        $this->processes,
        $resolver,
        Mockery::mock(UserOptionPresenter::class),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses a decision or a cancellation without the permission before any approval process is signalled', function (): void {
    $spy = GateSpy::allowing();

    $this->processes->shouldNotReceive('startWithUpdate');

    $processId = (string) $this->process->getKey();

    expect(fn () => $this->controller->decide(($this->request)(['decision' => 'approved']), $processId))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->controller->cancel(($this->request)(['reason' => 'obsolete']), $processId))->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('decide', $this->process))->toBeTrue()
        ->and($spy->wasAskedFor('cancel', $this->process))->toBeTrue();
});

it('hands the decision of the acting user to the tenant approval process with the decision fields only', function (): void {
    GateSpy::allowing('decide');

    $started = null;

    $this->processes->shouldReceive('startWithUpdate')
        ->once()
        ->andReturnUsing(function (ProcessStartData $process, string $update, array $arguments) use (&$started): never {
            $started = ['process' => $process, 'update' => $update, 'command' => $arguments[0]];

            throw new RuntimeException('started');
        });

    $request = ($this->request)(['decision' => 'rejected', 'reason' => 'Budget fehlt', 'tenant_id' => 'foreign-tenant', 'actor_id' => 'someone-else']);

    expect(fn () => $this->controller->decide($request, (string) $this->process->getKey()))->toThrow(RuntimeException::class, 'started');

    $command = $started['command'];

    expect($started['process']->workflow)->toBe(ApprovalProcessWorkflowInterface::class)
        ->and($started['process']->workflowId)->toBe("approval-process:{$this->tenant->getKey()}:{$this->process->getKey()}")
        ->and($started['update'])->toBe('decide')
        ->and($command)->toBeInstanceOf(ApprovalCommandData::class)
        ->and($command->tenantId)->toBe((string) $this->tenant->getKey())
        ->and($command->actorId)->toBe((string) $this->actor->getKey())
        ->and($command->input)->toBe(['decision' => 'rejected', 'reason' => 'Budget fehlt']);
});

it('loads the deciding user only inside the tenant of the approval process', function (): void {
    Log::spy();

    $binder = Mockery::mock(TenantBinder::class);
    $binder->shouldReceive('run')->andReturnUsing(fn (string $tenantId, callable $work): mixed => $work($this->tenant));

    $decide = Mockery::mock(DecideApprovalAction::class);
    $decide->shouldNotReceive('execute');

    $activity = new ApprovalProcessActivity(
        Mockery::mock(DescribeApprovalProcessAction::class),
        $decide,
        Mockery::mock(CancelApprovalProcessAction::class),
        Mockery::mock(NotifyApprovalStageStartedAction::class),
        Mockery::mock(EscalateApprovalStageAction::class),
        Mockery::mock(NotifyApprovalEscalationAction::class),
        Mockery::mock(NotifyApprovalDecisionAction::class),
        new ProcessStepRunner(new ActivityFailures(Mockery::mock(Repository::class))),
        $binder,
    );

    $foreignActorId = ModelStub::ulid('foreign-actor');

    $query = QueryShape::attemptedBy(fn () => $activity->decide(new ApprovalCommandData(
        (string) $this->tenant->getKey(),
        (string) $this->process->getKey(),
        $foreignActorId,
        ['decision' => 'approved'],
    )));

    expect($query)->not->toBeNull()
        ->and($query->targets('users'))->toBeTrue()
        ->and($query->isScopedToTenant('users', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($query->isKeyedTo('users', $foreignActorId))->toBeTrue();
});
