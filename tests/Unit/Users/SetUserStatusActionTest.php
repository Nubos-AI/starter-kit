<?php

declare(strict_types=1);

use App\Actions\Users\SetUserStatusAction;
use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Authorization\SelfLockoutGuard;
use App\Support\Users\UserSessionInvalidator;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $callback): mixed => $callback());

    $this->guard = Mockery::mock(SelfLockoutGuard::class);
    $this->sessions = Mockery::mock(UserSessionInvalidator::class);
    $this->tokens = Mockery::mock(MorphMany::class);

    $this->action = fn (): SetUserStatusAction => new SetUserStatusAction($this->guard, $this->sessions);

    /** @var callable():User */
    $this->target = function (): User {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('save')->andReturnTrue();
        $user->shouldReceive('tokens')->andReturn($this->tokens);

        return $user;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('rejects a status the enum does not know', function (): void {
    $this->guard->shouldNotReceive('assertUserIsNotLastEscalatedHolder');
    $this->sessions->shouldNotReceive('invalidate');
    $this->tokens->shouldNotReceive('delete');

    expect(fn () => ($this->action)()->execute(($this->target)(), ['status' => 'deleted']))
        ->toThrow(ValidationException::class);
});

it('rejects a payload without a status', function (): void {
    expect(fn () => ($this->action)()->execute(($this->target)(), []))
        ->toThrow(ValidationException::class);
});

it('revokes every personal access token of a user it blocks', function (): void {
    $target = ($this->target)();

    $this->guard->shouldReceive('assertUserIsNotLastEscalatedHolder')->once()->with($target);
    $this->sessions->shouldReceive('invalidate')->once()->with($target);
    $this->tokens->shouldReceive('delete')->once();

    ($this->action)()->execute($target, ['status' => UserStatus::Blocked->value]);

    expect($target->status)->toBe(UserStatus::Blocked);
});

it('asks the lockout guard before it writes the blocked status', function (): void {
    $target = ($this->target)();
    $order = [];

    $this->guard->shouldReceive('assertUserIsNotLastEscalatedHolder')
        ->once()
        ->andReturnUsing(function () use (&$order): void {
            $order[] = 'guard';
        });

    $this->sessions->shouldReceive('invalidate')->once()->andReturnUsing(function () use (&$order): void {
        $order[] = 'sessions';
    });

    $this->tokens->shouldReceive('delete')->once()->andReturnUsing(function () use (&$order): int {
        $order[] = 'tokens';

        return 1;
    });

    ($this->action)()->execute($target, ['status' => UserStatus::Blocked->value]);

    expect($order)->toBe(['guard', 'sessions', 'tokens']);
});

it('leaves sessions and tokens alone when it unblocks a user', function (): void {
    $target = ($this->target)();

    $this->guard->shouldNotReceive('assertUserIsNotLastEscalatedHolder');
    $this->sessions->shouldNotReceive('invalidate');
    $this->tokens->shouldNotReceive('delete');

    ($this->action)()->execute($target, ['status' => UserStatus::Accepted->value]);

    expect($target->status)->toBe(UserStatus::Accepted);
});
