<?php

declare(strict_types=1);

use App\Contracts\Tenancy\PublicSiteProviderInterface;
use App\DTOs\Tenancy\PublicSitePageData;
use App\DTOs\Tenancy\SiteAddress;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Http\Controllers\Tenancy\WelcomeController;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\PublicSiteProviders;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
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
    Mockery::close();
});

it('shows a guest the welcome page instead of sending them to the login', function (): void {
    $response = ($this->visitHome)(null);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true)['component'])->toBe('Welcome')
        ->and($response->getData(true)['props']['canRegister'])->toBeTrue()
        ->and($response->getData(true)['props']['tenant'])->toBeNull();
});

it('still sends a signed-in user with a team to that team dashboard', function (): void {
    $tenant = AccessContext::tenant();
    $teamKey = (string) ModelStub::ulid('sales-team');
    $user = AccessContext::user($tenant, ['current_team_id' => $teamKey]);

    $response = ($this->visitHome)($user);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe(route('dashboard', ['activeTeam' => $teamKey]));
});

it('serves every page of a site behind its address after every other route so a page never shadows one', function (): void {
    $route = RouteShape::named('home.tenant');

    expect($route->uri())->toBe('{sitePath}')
        ->and($route->handledBy())->toBe(WelcomeController::class)
        ->and(RouteShape::named('home')->handledBy())->toBe(WelcomeController::class)
        ->and($route->constraints())->toBe(['sitePath' => '[a-z0-9-]+(?:/[a-z0-9-]+)*'])
        ->and(Route::getRoutes()->getByName('home.tenant')->isFallback)->toBeTrue()
        ->and(Route::getRoutes()->match(Request::create('/login'))->getName())->toBe('login')
        ->and(Route::getRoutes()->match(Request::create('/acme'))->getName())->toBe('home.tenant')
        ->and(Route::getRoutes()->match(Request::create('/acme/vertrieb/kontakt'))->getName())->toBe('home.tenant');
});

it('renders the page a site provider delivers for the address to guests and signed-in users alike', function (?string $userSeed): void {
    $tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme'), 'name' => 'Acme', 'slug' => 'acme']);
    $address = new SiteAddress($tenant, null, 'kontakt');
    $resolver = Mockery::mock(WelcomeTenantResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn($address);
    app()->instance(WelcomeTenantResolver::class, $resolver);
    $user = $userSeed === null ? null : AccessContext::user(AccessContext::tenant(), ['current_team_id' => (string) ModelStub::ulid($userSeed)]);
    $provider = Mockery::mock(PublicSiteProviderInterface::class);
    $provider->shouldReceive('pageOf')->once()->with($address, $user)->andReturn(new PublicSitePageData('crm/sites/default/Contact', ['siteName' => 'Acme']));
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([$provider]));

    $response = ($this->visitHome)($user);

    expect($response->getData(true)['component'])->toBe('crm/sites/default/Contact')
        ->and($response->getData(true)['props']['siteName'])->toBe('Acme');
})->with(['guest' => [null], 'signed-in user' => ['sales-team']]);

it('answers not found for a team site or a page no site provider delivers', function (?string $teamSeed, string $path): void {
    $tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme'), 'name' => 'Acme', 'slug' => 'acme']);
    $team = $teamSeed === null ? null : ModelStub::make(Team::class, ['id' => ModelStub::ulid($teamSeed), 'slug' => $teamSeed]);
    $resolver = Mockery::mock(WelcomeTenantResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(new SiteAddress($tenant, $team, $path));
    app()->instance(WelcomeTenantResolver::class, $resolver);
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([]));

    expect(fn () => ($this->visitHome)(null))->toThrow(NotFoundHttpException::class);
})->with(['team site' => ['vertrieb', ''], 'page of the tenant site' => [null, 'kontakt']]);

it('shows a guest the welcome page of the tenant the address names', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Path->value]);
    $resolver = Mockery::mock(WelcomeTenantResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(new SiteAddress(ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme'), 'name' => 'Acme', 'slug' => 'acme'])));
    app()->instance(WelcomeTenantResolver::class, $resolver);
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([]));

    $response = ($this->visitHome)(null);

    expect($response->getData(true)['component'])->toBe('Welcome')
        ->and($response->getData(true)['props']['tenant'])->toBe(['name' => 'Acme']);
});

it('answers not found for a welcome address no tenant carries', function (): void {
    $resolver = Mockery::mock(WelcomeTenantResolver::class);
    $resolver->shouldReceive('resolve')->once()->andThrow((new ModelNotFoundException)->setModel(Tenant::class));
    app()->instance(WelcomeTenantResolver::class, $resolver);

    expect(fn () => ($this->visitHome)(null))->toThrow(ModelNotFoundException::class);
});
