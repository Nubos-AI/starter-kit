<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Inertia\Response as InertiaResponse;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(?User):mixed */
    $this->visitHome = function (?User $user): mixed {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Inertia', 'true');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));

        app()->instance('request', $request);

        $request->setUserResolver(static fn (): ?User => $user);

        $response = Route::getRoutes()->getByName('home')->bind($request)->run();

        return $response instanceof InertiaResponse
            ? $response->toResponse($request)
            : $response;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('shows a guest the welcome page instead of sending them to the login', function (): void {
    $response = ($this->visitHome)(null);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true)['component'])->toBe('Welcome')
        ->and($response->getData(true)['props']['canRegister'])->toBeTrue();
});

it('still sends a signed-in user with a team to that team dashboard', function (): void {
    $tenant = AccessContext::tenant();
    $teamKey = (string) ModelStub::ulid('sales-team');
    $user = AccessContext::user($tenant, ['current_team_id' => $teamKey]);

    $response = ($this->visitHome)($user);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe(route('dashboard', ['activeTeam' => $teamKey]));
});
