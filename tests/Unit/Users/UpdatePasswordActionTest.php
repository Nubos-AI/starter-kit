<?php

declare(strict_types=1);

use App\Actions\Users\UpdatePasswordAction;
use App\Http\Controllers\Settings\SecurityController;
use App\Models\User;
use App\Support\Users\UserSessionInvalidator;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    $this->user = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('ada'),
        'email' => 'ada@acme.de',
        'password' => Hash::make('old-secret-password'),
        'remember_token' => 'remembered',
    ]);

    $this->actingAs($this->user);

    $this->sessionId = str_repeat('a', 40);

    $this->payload = [
        'current_password' => 'old-secret-password',
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('ends every other session of the user but keeps the current one when the user changes the own password', function (): void {
    (new UpdatePasswordAction(new UserSessionInvalidator))->execute($this->user, $this->payload, $this->sessionId);

    $sessionDelete = collect($this->connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'delete from "sessions"'));

    expect($sessionDelete)->not->toBeNull()
        ->and($sessionDelete['sql'])->toBe('delete from "sessions" where "user_id" = ? and "id" != ?')
        ->and($sessionDelete['bindings'])->toBe([ModelStub::ulid('ada'), $this->sessionId])
        ->and($this->user->remember_token)->toBeNull()
        ->and(Hash::check('new-secret-password', $this->user->password))->toBeTrue();
});

it('ends every session of the user when no session is named to keep', function (): void {
    (new UserSessionInvalidator)->invalidate($this->user);

    expect($this->connection->writtenSqlOf('sessions'))
        ->toBe(['delete from "sessions" where "user_id" = ?']);
});

it('touches neither the password nor a session when the current password is wrong', function (): void {
    $invalidator = Mockery::mock(UserSessionInvalidator::class);
    $invalidator->shouldNotReceive('invalidate');

    expect(fn () => (new UpdatePasswordAction($invalidator))->execute(
        $this->user,
        [...$this->payload, 'current_password' => 'wrong-password'],
        $this->sessionId,
    ))->toThrow(ValidationException::class);

    expect($this->connection->writtenStatements)->toBe([]);
});

it('hands the id of the current session to the action so the device that changed the password stays signed in', function (): void {
    $action = Mockery::mock(UpdatePasswordAction::class);
    $action->shouldReceive('execute')->once()->with($this->user, $this->payload, $this->sessionId);

    $session = new Store('nubos_session', new ArraySessionHandler(120), $this->sessionId);
    $request = Request::create('/settings/password', 'PUT', $this->payload);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn (): User => $this->user);

    (new SecurityController)->update($request, $action);
});

it('revokes every personal access token of the user and only of that user when the sessions end', function (): void {
    (new UserSessionInvalidator)->invalidate($this->user);

    $tokenDelete = collect($this->connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'delete from "personal_access_tokens"'));

    expect($tokenDelete)->not->toBeNull()
        ->and($tokenDelete['sql'])->toBe('delete from "personal_access_tokens" where "personal_access_tokens"."tokenable_type" = ? and "personal_access_tokens"."tokenable_id" = ? and "personal_access_tokens"."tokenable_id" is not null')
        ->and($tokenDelete['bindings'])->toBe([$this->user->getMorphClass(), ModelStub::ulid('ada')]);
});
