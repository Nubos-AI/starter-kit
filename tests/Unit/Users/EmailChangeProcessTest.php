<?php

declare(strict_types=1);

use App\Actions\Users\BulkDeleteUsersAction;
use App\Actions\Users\ConfirmEmailChangeAction;
use App\Actions\Users\DeleteUserAction;
use App\Actions\Users\UpdateManagedUserAction;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\Contracts\Users\ConfirmEmailChangeWorkflowInterface;
use App\Contracts\Users\RequestEmailChangeWorkflowInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\Http\Controllers\Users\EmailChangeConfirmationController;
use App\Http\Controllers\Users\UsersController;
use App\Models\User;
use App\Support\Authorization\AssignableRoleOptions;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionOptions;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Teams\TenantTeamOptions;
use App\Support\Temporal\ProcessFingerprints;
use App\Support\Users\EmailChangeProcess;
use App\Support\Users\ManageableUserRows;
use App\Support\Users\TenantUserResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'admin');
    $this->target = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('target-user'),
        'tenant_id' => $this->tenant->getKey(),
        'email' => 'old@example.test',
    ]);

    $this->encrypter = Mockery::mock(Encrypter::class);
    $this->encrypter->shouldReceive('encryptString')->andReturn('sealed-value');

    $this->processes = Mockery::mock(ProcessStarterInterface::class);
    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('app.key')->andReturn('test-signing-key');

    $this->emailChange = new EmailChangeProcess($this->processes, $this->encrypter, new ProcessFingerprints($config));

    $this->captureStart = function (): Closure {
        $captured = new ArrayObject;

        $this->processes->shouldReceive('startWithUpdate')
            ->once()
            ->andReturnUsing(function (ProcessStartData $process, string $update, array $arguments) use ($captured): bool {
                $captured['process'] = $process;
                $captured['update'] = $update;
                $captured['arguments'] = $arguments;

                return true;
            });

        return fn (): ArrayObject => $captured;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses a managed user update without the update permission before an email change process starts', function (): void {
    $spy = GateSpy::allowing();

    $users = Mockery::mock(TenantUserResolver::class)->makePartial();
    $users->shouldReceive('resolve')->with($this->actor, (string) $this->target->getKey())->andReturn($this->target);

    $updateManagedUser = Mockery::mock(UpdateManagedUserAction::class);
    $updateManagedUser->shouldNotReceive('execute');
    $this->processes->shouldNotReceive('startWithUpdate');

    $controller = new UsersController(
        $updateManagedUser,
        Mockery::mock(DeleteUserAction::class),
        Mockery::mock(BulkDeleteUsersAction::class),
        $users,
        Mockery::mock(AssignableRoleOptions::class),
        Mockery::mock(TenantTeamOptions::class),
        Mockery::mock(ManageableUserRows::class),
        Mockery::mock(PermissionOptions::class),
        Mockery::mock(AuthorizationDirectory::class),
        $this->emailChange,
    );

    $request = Request::create('/probe', 'PUT', ['email' => 'new@example.test']);
    $request->setUserResolver(fn (): User => $this->actor);

    expect(fn () => $controller->update($request, (string) $this->target->getKey()))->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('update', $this->target))->toBeTrue();
});

it('starts the email change request of the tenant user with the sealed address only, never the plaintext address', function (): void {
    $captured = ($this->captureStart)();

    $this->emailChange->request($this->target, 'new-secret-address@example.test');

    $started = $captured();

    expect($started['process']->workflow)->toBe(RequestEmailChangeWorkflowInterface::class)
        ->and($started['process']->workflowId)->toStartWith("user-email-change-request:{$this->tenant->getKey()}:{$this->target->getKey()}:")
        ->and($started['update'])->toBe('requestEmailChange')
        ->and($started['arguments'][0]->tenantId)->toBe((string) $this->tenant->getKey())
        ->and($started['arguments'][0]->sealedValue)->toBe('sealed-value')
        ->and(serialize([$started['process'], $started['arguments']]))->not->toContain('new-secret-address@example.test');
});

it('answers a confirmation without an open request with not found and starts no confirmation process', function (): void {
    $this->processes->shouldNotReceive('startWithUpdate');

    $controller = new EmailChangeConfirmationController(
        new ConfirmEmailChangeAction(Mockery::mock(SsoEnforcementGate::class)),
        $this->emailChange,
    );

    $request = Request::create('/probe', 'POST');
    $request->setUserResolver(fn (): User => $this->target);

    expect(fn () => $controller->store($request, 'guessed-token'))->toThrow(NotFoundHttpException::class);
});

it('starts the confirmation with the sealed token only, never the plaintext token', function (): void {
    $captured = ($this->captureStart)();

    $this->emailChange->confirm($this->target, 'plaintext-confirmation-token');

    $started = $captured();

    expect($started['process']->workflow)->toBe(ConfirmEmailChangeWorkflowInterface::class)
        ->and($started['process']->workflowId)->toStartWith("user-email-change-confirmation:{$this->tenant->getKey()}:{$this->target->getKey()}:")
        ->and($started['update'])->toBe('confirmEmailChange')
        ->and(serialize([$started['process'], $started['arguments']]))->not->toContain('plaintext-confirmation-token');
});
