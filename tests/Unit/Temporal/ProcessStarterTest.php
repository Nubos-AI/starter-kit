<?php

declare(strict_types=1);

use App\Contracts\Users\ChangeUserPasswordWorkflowInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\DTOs\Users\ChangeUserPasswordData;
use App\Enums\Temporal\ProcessFailureType;
use App\Exceptions\Temporal\ProcessStepFailedException;
use App\Exceptions\Temporal\ProcessStepRejectedException;
use App\Support\Temporal\ActivityFailures;
use App\Support\Temporal\ProcessFingerprints;
use App\Support\Temporal\ProcessUpdateId;
use App\Support\Temporal\TemporalProcessStarter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Validation\ValidationException;
use Temporal\Client\Update\UpdateOptions;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\WorkflowUpdateException;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Workflow\WorkflowExecution;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->config = Mockery::mock(Repository::class);
    $this->config->shouldReceive('get')->with('temporal.queue')->andReturn('default');
    $this->config->shouldReceive('get')->with('temporal.processes.update_timeout_seconds')->andReturn(30);
    $this->config->shouldReceive('get')->with('temporal.failures.message_limit')->andReturn(300);
    $this->config->shouldReceive('get')->with('app.key')->andReturn('test-signing-key');

    $this->client = Mockery::mock(WorkflowClientInterface::class);
    $this->client->shouldReceive('newWorkflowStub')->andReturn(new stdClass);

    $this->starter = new TemporalProcessStarter($this->client, $this->config, new ActivityFailures($this->config), new ProcessFingerprints($this->config));
    $this->process = new ProcessStartData(ChangeUserPasswordWorkflowInterface::class, 'user-password-change:tenant:user:key');

    /** @var callable(Throwable):WorkflowUpdateException */
    $this->updateFailedWith = fn (Throwable $cause): WorkflowUpdateException => new WorkflowUpdateException(
        'update failed',
        new WorkflowExecution('user-password-change:tenant:user:key', 'run'),
        'ChangeUserPassword',
        'update-id',
        'setPassword',
        new ActivityFailure(1, 2, 'users.setUserPassword', 'activity-id', 0, 'identity', $cause),
    );
});

afterEach(function (): void {
    Mockery::close();
});

it('binds a validation failure of the first step to the field the action named, even after it crossed temporal', function (): void {
    $failure = (new ActivityFailures($this->config))->rejected(
        ValidationException::withMessages(['password' => ['Das Passwort ist zu kurz.']]),
    );

    $this->client->shouldReceive('updateWithStart')->andThrow(($this->updateFailedWith)($failure));

    try {
        $this->starter->startWithUpdate($this->process, 'setPassword');
    } catch (ProcessStepRejectedException $rejection) {
        expect($rejection)->toBeInstanceOf(ValidationException::class)
            ->and($rejection->errors())->toBe(['password' => ['Das Passwort ist zu kurz.']])
            ->and($failure->isNonRetryable())->toBeTrue();

        return;
    }

    test()->fail('The rejection of the first step did not reach the caller as a field error.');
});

it('reports any other failure of the first step as a failed step instead of a field error', function (): void {
    $this->client->shouldReceive('updateWithStart')->andThrow(($this->updateFailedWith)(
        new ApplicationFailure('SMTP down', RuntimeException::class, false),
    ));

    expect(fn () => $this->starter->startWithUpdate($this->process, 'setPassword'))
        ->toThrow(ProcessStepFailedException::class);
});

it('keys every update to an hmac of the plain input, so a resent form matches and the plain input never reaches temporal', function (): void {
    $encrypter = new Encrypter(str_repeat('k', 32), 'aes-256-cbc');
    $password = ['password' => 'Geheim-Passwort-123', 'password_confirmation' => 'Geheim-Passwort-123'];
    $updateIds = [];

    $this->client->shouldReceive('updateWithStart')->andReturnUsing(function (object $stub, UpdateOptions $update) use (&$updateIds): never {
        $updateIds[] = (string) $update->updateId;

        throw ($this->updateFailedWith)(new ApplicationFailure('SMTP down', RuntimeException::class, false));
    });

    foreach ([$password, $password, ['password' => 'Anderes-Passwort-456', 'password_confirmation' => 'Anderes-Passwort-456']] as $input) {
        try {
            $this->starter->startWithUpdate($this->process, 'setPassword', [ChangeUserPasswordData::sealed($encrypter, 'tenant', 'user', $input)]);
        } catch (ProcessStepFailedException) {
        }
    }

    [$first, $resent, $other] = array_map(ProcessUpdateId::fingerprintOf(...), $updateIds);

    expect($first)->not->toBeNull()
        ->and($resent)->toBe($first)
        ->and($other)->not->toBe($first)
        ->and($updateIds[0])->not->toBe($updateIds[1])
        ->and(implode('|', $updateIds))->not->toContain('Geheim-Passwort-123')
        ->and(implode('|', $updateIds))->not->toContain(hash('sha256', (string) json_encode($password)));
});

it('refuses a submission that met a process already answering other input on the field the form renders', function (): void {
    $this->client->shouldReceive('updateWithStart')->andThrow(($this->updateFailedWith)(
        new ApplicationFailure('The process step was already submitted with different input.', ProcessFailureType::Conflicting->value, true),
    ));

    $process = new ProcessStartData(ChangeUserPasswordWorkflowInterface::class, 'user-password-change:tenant:user:key', submissionField: 'password');

    try {
        $this->starter->startWithUpdate($process, 'setPassword');
    } catch (ProcessStepRejectedException $rejection) {
        expect(array_keys($rejection->errors()))->toBe(['password']);

        return;
    }

    test()->fail('A conflicting submission did not reach the caller as a field error.');
});

it('answers a step the worker refused for missing rights with 403 and a vanished record with 404', function (): void {
    $this->client->shouldReceive('updateWithStart')->andThrowExceptions([
        ($this->updateFailedWith)(new ApplicationFailure('This action is unauthorized.', AuthorizationException::class, true)),
        ($this->updateFailedWith)(new ApplicationFailure('No query results.', ModelNotFoundException::class, true)),
    ]);

    expect(fn () => $this->starter->startWithUpdate($this->process, 'setPassword'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->starter->startWithUpdate($this->process, 'setPassword'))->toThrow(ModelNotFoundException::class);
});
