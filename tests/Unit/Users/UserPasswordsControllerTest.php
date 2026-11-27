<?php

declare(strict_types=1);

use App\Actions\Users\SendUserPasswordResetLinkAction;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\Contracts\Users\ChangeUserPasswordWorkflowInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\DTOs\Users\ChangeUserPasswordData;
use App\Http\Controllers\Users\UserPasswordsController;
use App\Models\User;
use App\Support\Temporal\ProcessFingerprints;
use App\Support\Users\TenantUserResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
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
        'email' => 'target@example.test',
    ]);

    $this->users = Mockery::mock(TenantUserResolver::class)->makePartial();
    $this->users->shouldReceive('resolve')->with($this->actor, (string) $this->target->getKey())->andReturn($this->target);

    $this->encrypter = Mockery::mock(Encrypter::class);
    $this->encrypter->shouldReceive('encryptString')->andReturn('sealed-password-input');

    $this->processes = Mockery::mock(ProcessStarterInterface::class);

    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('app.key')->andReturn('test-signing-key');

    $this->request = Request::create('/probe', 'PUT', [
        'password' => 'Plaintext-Secret-91!',
        'password_confirmation' => 'Plaintext-Secret-91!',
        'tenant_id' => 'foreign-tenant',
    ]);
    $this->request->setUserResolver(fn (): User => $this->actor);

    $this->controller = new UserPasswordsController(
        $this->users,
        $this->processes,
        $this->encrypter,
        Mockery::mock(SendUserPasswordResetLinkAction::class),
        new ProcessFingerprints($config),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to change the password of a user without the updatePassword permission before any process starts', function (): void {
    $spy = GateSpy::allowing();

    $this->processes->shouldNotReceive('startWithUpdate');

    expect(fn () => $this->controller->update($this->request, (string) $this->target->getKey()))->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('updatePassword', $this->target))->toBeTrue();
});

it('starts the password change of the tenant user with the sealed input only, never the plaintext password', function (): void {
    GateSpy::allowing('updatePassword');

    $started = null;

    $this->processes->shouldReceive('startWithUpdate')
        ->once()
        ->andReturnUsing(function (ProcessStartData $process, string $update, array $arguments) use (&$started): never {
            $started = ['process' => $process, 'update' => $update, 'arguments' => $arguments];

            throw new RuntimeException('started');
        });

    expect(fn () => $this->controller->update($this->request, (string) $this->target->getKey()))->toThrow(RuntimeException::class, 'started');

    $input = $started['arguments'][0];

    expect($started['process']->workflow)->toBe(ChangeUserPasswordWorkflowInterface::class)
        ->and($started['process']->workflowId)->toStartWith("user-password-change:{$this->tenant->getKey()}:{$this->target->getKey()}:")
        ->and($started['update'])->toBe('setPassword')
        ->and($input)->toBeInstanceOf(ChangeUserPasswordData::class)
        ->and($input->tenantId)->toBe((string) $this->tenant->getKey())
        ->and($input->userId)->toBe((string) $this->target->getKey())
        ->and($input->sealedInput)->toBe('sealed-password-input')
        ->and(serialize([$started['process'], $started['arguments']]))->not->toContain('Plaintext-Secret-91!');
});

it('sends a resent password change to the process already running for it instead of starting a second one', function (): void {
    GateSpy::allowing('updatePassword');

    $workflowIds = [];

    $this->processes->shouldReceive('startWithUpdate')
        ->twice()
        ->andReturnUsing(function (ProcessStartData $process) use (&$workflowIds): never {
            $workflowIds[] = $process->workflowId;

            throw new RuntimeException('started');
        });

    expect(fn () => $this->controller->update($this->request, (string) $this->target->getKey()))->toThrow(RuntimeException::class, 'started')
        ->and(fn () => $this->controller->update($this->request, (string) $this->target->getKey()))->toThrow(RuntimeException::class, 'started');

    expect($workflowIds[0])->toBe($workflowIds[1])
        ->and($workflowIds[0])->not->toContain('Plaintext-Secret-91!');
});
