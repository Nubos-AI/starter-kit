<?php

declare(strict_types=1);

use App\DTOs\Users\ChangeUserPasswordData;
use App\Workflows\Users\ChangeUserPasswordWorkflow;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    WorkflowRun::end();
});

it('sets a password exactly once and still tells the user when the first step window closes while the password is being set', function (): void {
    $input = new ChangeUserPasswordData('tenant-1', 'user-1', 'sealed-input');
    $workflow = new ChangeUserPasswordWorkflow;

    $run = WorkflowRun::start(fn (): Generator => $workflow->run($input))
        ->handle('setPassword', $workflow->setPassword($input))
        ->handle('setPasswordAgain', $workflow->setPassword($input));

    expect($run->waitsFor('setPassword'))->toBe('users.setUserPassword')
        ->and($run->waitsFor('setPasswordAgain'))->toBe('await');

    $run->fireTimer();

    expect($run->activitiesNamed('users.notifyPasswordChanged'))->toBe([])
        ->and($run->isFinished())->toBeFalse();

    $run->resume(null, 'setPassword');

    expect($run->activitiesNamed('users.setUserPassword'))->toHaveCount(1)
        ->and($run->resultOf('setPassword'))->toBeTrue()
        ->and($run->resultOf('setPasswordAgain'))->toBeTrue()
        ->and($run->activitiesNamed('users.notifyPasswordChanged'))->toBe([['handler' => 'run', 'arguments' => ['tenant-1', 'user-1', 'workflow-run-test-run']]]);

    $run->resume();

    expect($run->isFinished())->toBeTrue();
});
