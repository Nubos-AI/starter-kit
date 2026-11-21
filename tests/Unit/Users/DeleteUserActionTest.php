<?php

declare(strict_types=1);

use App\Actions\Users\DeleteOwnAccountAction;
use App\Actions\Users\DeleteUserAction;
use App\Enums\Users\UserStatus;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Models\User;
use App\Support\Authorization\SelfLockoutGuard;
use App\Support\Users\UserSessionInvalidator;
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
    $this->sessions->shouldReceive('invalidate')->byDefault();
    $this->deleteUser = fn (): DeleteUserAction => new DeleteUserAction($this->guard, $this->sessions);

    /** @var callable():User */
    $this->target = function (): User {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('save')->andReturnTrue();
        $user->shouldReceive('tokens->delete')->byDefault()->andReturn(0);

        return $user;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to delete the last escalated holder and writes nothing', function (): void {
    $target = ($this->target)();
    $target->shouldNotReceive('delete');
    $this->sessions->shouldNotReceive('invalidate');

    $this->guard->shouldReceive('assertUserIsNotLastEscalatedHolder')
        ->once()
        ->andThrow(SelfLockoutException::lastEscalatedHolderCannotBeDeleted());

    expect(fn () => ($this->deleteUser)()->execute($target))
        ->toThrow(SelfLockoutException::class);
});

it('marks the account as deleted before it soft deletes the row', function (): void {
    $target = ($this->target)();
    $observed = null;

    $this->guard->shouldReceive('assertUserIsNotLastEscalatedHolder')->once();
    $target->shouldReceive('delete')->once()->andReturnUsing(function () use ($target, &$observed): bool {
        $observed = $target->status;

        return true;
    });

    ($this->deleteUser)()->execute($target);

    expect($observed)->toBe(UserStatus::Deleted);
});

it('refuses to delete the own account without the current password', function (): void {
    $this->guard->shouldNotReceive('assertUserIsNotLastEscalatedHolder');

    $target = ($this->target)();
    $target->shouldNotReceive('delete');

    $action = new DeleteOwnAccountAction(($this->deleteUser)());

    expect(fn () => $action->execute($target, []))
        ->toThrow(ValidationException::class);
});

it('ends every session, the remembered login and every api token of the deleted account so a restore revives none of them', function (): void {
    $target = ($this->target)();
    $order = [];

    $this->guard->shouldReceive('assertUserIsNotLastEscalatedHolder')->once();
    $this->sessions->shouldReceive('invalidate')->once()->with($target)->andReturnUsing(function () use (&$order): void {
        $order[] = 'sessions';
    });
    $target->shouldReceive('tokens->delete')->once()->andReturnUsing(function () use (&$order): int {
        $order[] = 'tokens';

        return 2;
    });
    $target->shouldReceive('delete')->once()->andReturnUsing(function () use (&$order): bool {
        $order[] = 'row';

        return true;
    });

    ($this->deleteUser)()->execute($target);

    expect($order)->toBe(['sessions', 'tokens', 'row']);
});
