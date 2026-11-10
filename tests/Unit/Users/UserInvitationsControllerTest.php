<?php

declare(strict_types=1);

use App\Actions\Users\ResendInvitationAction;
use App\Enums\Users\UserStatus;
use App\Http\Controllers\Users\UserInvitationsController;
use App\Models\User;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'subtree-manager'));
    $this->invitee = AccessContext::user($this->tenant, ['status' => UserStatus::Invited->value, 'email' => 'invitee@example.test'], 'invitee');

    StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "users"') ? [$this->invitee->getAttributes()] : [],
        static fn (): int => 1,
    );

    $this->resend = Mockery::mock(ResendInvitationAction::class);
    app()->instance(ResendInvitationAction::class, $this->resend);

    $this->request = function (): Request {
        $request = Request::create('/probe', 'POST');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->setUserResolver(fn (): User => $this->actor);
        app()->instance('request', $request);

        return $request;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to resend an invitation to a user outside the reach of the actor before the action runs', function (): void {
    $gate = GateSpy::allowing();
    $this->resend->shouldNotReceive('execute');

    expect(fn () => app(UserInvitationsController::class)->resend(($this->request)(), ModelStub::ulid('invitee')))
        ->toThrow(AuthorizationException::class)
        ->and($gate->calls[0]['ability'])->toBe('resendInvitation')
        ->and($gate->calls[0]['arguments'][0]->getKey())->toBe(ModelStub::ulid('invitee'));
});

it('resends the invitation once the actor may reach the invited user', function (): void {
    GateSpy::allowing('resendInvitation');
    $this->resend->shouldReceive('execute')->once()->withArgs(fn (User $target, User $actor): bool => $target->getKey() === ModelStub::ulid('invitee') && $actor === $this->actor);

    $response = app(UserInvitationsController::class)->resend(($this->request)(), ModelStub::ulid('invitee'));

    expect($response->getTargetUrl())->toBe(route('engine.users.index'));
});
