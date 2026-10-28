<?php

declare(strict_types=1);

use App\Actions\Users\DeleteOwnAccountAction;
use App\Actions\Users\DeleteUserAction;
use App\Enums\Users\UserStatus;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Models\User;
use App\Support\Authorization\SelfLockoutGuard;
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
    $this->deleteUser = fn (): DeleteUserAction => new DeleteUserAction($this->guard);

    /** @var callable():User */
    $this->target = function (): User {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('save')->andReturnTrue();

        return $user;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to delete the last escalated holder and writes nothing', function (): void {
    $target = ($this->target)();
    $target->shouldNotReceive('delete');

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
