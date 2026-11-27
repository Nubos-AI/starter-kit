<?php

declare(strict_types=1);

use App\DTOs\Users\ChangeUserPasswordData;
use App\Enums\Temporal\ProcessFailureType;
use App\Support\Temporal\ProcessUpdateId;
use App\Workflows\Users\ChangeUserPasswordWorkflow;
use Temporal\Exception\Failure\ApplicationFailure;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    WorkflowRun::end();
});

it('answers a repeated submission with the first result but refuses a submission whose input differs', function (): void {
    $input = new ChangeUserPasswordData('tenant-1', 'user-1', 'sealed-input');
    $other = new ChangeUserPasswordData('tenant-1', 'user-1', 'sealed-other-input');
    $workflow = new ChangeUserPasswordWorkflow;

    $run = WorkflowRun::start(fn (): Generator => $workflow->run($input))
        ->update('first', ProcessUpdateId::for(str_repeat('a', 64)), $workflow->setPassword($input))
        ->update('repeated', ProcessUpdateId::for(str_repeat('a', 64)), $workflow->setPassword($input))
        ->update('foreign', ProcessUpdateId::for(str_repeat('b', 64)), $workflow->setPassword($other));

    $run->resume(null, 'first');

    $refusal = $run->failureOf('foreign');

    expect($run->activitiesNamed('users.setUserPassword'))->toHaveCount(1)
        ->and($run->activitiesNamed('users.setUserPassword')[0]['arguments'])->toBe([$input])
        ->and($run->resultOf('first'))->toBeTrue()
        ->and($run->resultOf('repeated'))->toBeTrue()
        ->and($run->resultOf('foreign'))->toBeNull()
        ->and($refusal)->toBeInstanceOf(ApplicationFailure::class)
        ->and($refusal instanceof ApplicationFailure ? $refusal->getType() : null)->toBe(ProcessFailureType::Conflicting->value)
        ->and($refusal instanceof ApplicationFailure && $refusal->isNonRetryable())->toBeTrue();
});
