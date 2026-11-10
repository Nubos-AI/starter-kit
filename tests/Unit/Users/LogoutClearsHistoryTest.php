<?php

declare(strict_types=1);

use App\Actions\Users\DeleteOwnAccountAction;
use App\Http\Controllers\Settings\ProfilesController;
use App\Http\Responses\Auth\LogoutResponse;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Inertia\Support\SessionKey;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->requestWith = function (bool $wantsJson): Request {
        $request = Request::create('/logout', 'POST');
        $session = new Store('test', new ArraySessionHandler(10));
        $request->setLaravelSession($session);
        app()->instance('session.store', $session);
        app('session')->setDefaultDriver('array');

        if ($wantsJson) {
            $request->headers->set('Accept', 'application/json');
        }

        return $request;
    };
});

it('clears the browser history of the signed out user so cached pages and one time secrets vanish from the history state', function (): void {
    $request = ($this->requestWith)(false);

    $response = app(LogoutResponseContract::class)->toResponse($request);

    expect(app(LogoutResponseContract::class))->toBeInstanceOf(LogoutResponse::class)
        ->and($response)->toBeInstanceOf(RedirectResponse::class)
        ->and(session(SessionKey::CLEAR_HISTORY))->toBeTrue();
});

it('answers a json logout without content and still clears the history', function (): void {
    $response = app(LogoutResponseContract::class)->toResponse(($this->requestWith)(true));

    expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT)
        ->and(session(SessionKey::CLEAR_HISTORY))->toBeTrue();
});

it('encrypts the inertia page data before the browser keeps it in its history', function (): void {
    expect(config('inertia.history.encrypt'))->toBeTrue();
});

it('clears the browser history when a user deletes the own account and is signed out', function (): void {
    $tenant = AccessContext::tenant('logout-history-tenant');
    $user = AccessContext::actAs(AccessContext::user($tenant, [], 'logout-history-user'));
    $request = ($this->requestWith)(false);
    $request->setUserResolver(static fn (): User => $user);
    $deleteOwnAccount = Mockery::mock(DeleteOwnAccountAction::class);
    $deleteOwnAccount->shouldReceive('execute')->once();

    (new ProfilesController)->destroy($request, $deleteOwnAccount);

    expect(session(SessionKey::CLEAR_HISTORY))->toBeTrue();

    AccessContext::forgetTenant();
});
