<?php

declare(strict_types=1);

use App\Contracts\Tenancy\PublicSiteProviderInterface;
use App\DTOs\Tenancy\PublicSitePageData;
use App\DTOs\Tenancy\SiteAddress;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\PublicSiteProviders;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['tenancy.welcome.base_domain' => 'nubos.test', 'app.url' => 'https://app.nubos.test']);

    $this->tenantRow = ['id' => ModelStub::ulid('acme'), 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'chat.acme.de'];
    $this->teamRow = ['id' => ModelStub::ulid('sales'), 'tenant_id' => ModelStub::ulid('acme'), 'parent_team_id' => null, 'name' => 'Vertrieb', 'slug' => 'vertrieb'];
    $this->teamRows = [$this->teamRow];
    $this->connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "teams"') ? $this->teamRows : [$this->tenantRow]);

    $this->domainSite = null;
    $this->provider = new class($this) implements PublicSiteProviderInterface
    {
        /** @var list<string> */
        public array $askedDomains = [];

        public function __construct(private readonly object $test) {}

        public function pageOf(SiteAddress $address, ?User $visitor): ?PublicSitePageData
        {
            return null;
        }

        public function siteOfDomain(string $host): ?SiteAddress
        {
            $this->askedDomains[] = $host;

            return $this->test->domainSite;
        }

        public function claimsDomain(string $host): bool
        {
            return false;
        }
    };
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([$this->provider]));

    $this->request = function (string $url): Request {
        $request = Request::create($url);
        $path = trim($request->path(), '/');
        $route = new Route('GET', $path === '' ? '/' : '{sitePath}', static fn (): null => null);
        $route->bind($request);

        if ($path !== '') {
            $route->setParameter('sitePath', $path);
        }

        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('finds the tenant of a site by the slug in the path when addressed by path', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Path->value]);

    $address = app(WelcomeTenantResolver::class)->resolve(($this->request)('http://localhost/acme'));

    expect($address)->toBeInstanceOf(SiteAddress::class)
        ->and($address?->tenant->getKey())->toBe(ModelStub::ulid('acme'))
        ->and($address?->team)->toBeNull()
        ->and($address?->path)->toBe('')
        ->and($this->connection->queries)->toHaveCount(1)
        ->and($this->connection->queries[0]['sql'])->toContain('from "tenants"')->toContain('"slug" = ?')->toContain('"deleted_at" is null')
        ->and($this->connection->queries[0]['bindings'])->toBe(['acme']);
});

it('finds the site of a top level team behind the tenant slug when addressed by path', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Path->value]);

    $address = app(WelcomeTenantResolver::class)->resolve(($this->request)('http://localhost/acme/vertrieb/kontakt'));

    expect($address?->team?->getKey())->toBe(ModelStub::ulid('sales'))
        ->and($address?->path)->toBe('kontakt')
        ->and($this->connection->queries[1]['sql'])->toContain('from "teams"')->toContain('"tenant_id" = ?')->toContain('"parent_team_id" is null')->toContain('"slug" = ?')->toContain('"deleted_at" is null')->not->toContain('1 = 0')
        ->and($this->connection->queries[1]['bindings'])->toBe([ModelStub::ulid('acme'), 'vertrieb']);
});

it('reads a path segment no top level team carries as a page of the tenant site when addressed by path', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Path->value]);
    $this->teamRows = [];

    $address = app(WelcomeTenantResolver::class)->resolve(($this->request)('http://localhost/acme/kontakt'));

    expect($address?->team)->toBeNull()
        ->and($address?->path)->toBe('kontakt');
});

it('shows the welcome page without a tenant at the root when addressed by path', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Path->value]);

    expect(app(WelcomeTenantResolver::class)->resolve(($this->request)('http://acme.nubos.test/')))->toBeNull()
        ->and($this->connection->queries)->toBe([]);
});

it('finds the tenant of a site by the subdomain of the platform when addressed by host', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);

    $address = app(WelcomeTenantResolver::class)->resolve(($this->request)('https://acme.nubos.test/preise'));

    expect($address?->tenant->getKey())->toBe(ModelStub::ulid('acme'))
        ->and($address?->team)->toBeNull()
        ->and($address?->path)->toBe('preise')
        ->and($this->connection->queries)->toHaveCount(1)
        ->and($this->connection->queries[0]['bindings'])->toBe(['acme']);
});

it('finds the site of a top level team by its subdomain below the tenant subdomain when addressed by host', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);

    $address = app(WelcomeTenantResolver::class)->resolve(($this->request)('https://Vertrieb.Acme.nubos.test/'));

    expect($address?->tenant->getKey())->toBe(ModelStub::ulid('acme'))
        ->and($address?->team?->getKey())->toBe(ModelStub::ulid('sales'))
        ->and($address?->path)->toBe('')
        ->and($this->connection->queries[1]['sql'])->toContain('from "teams"')->toContain('"parent_team_id" is null')
        ->and($this->connection->queries[1]['bindings'])->toBe([ModelStub::ulid('acme'), 'vertrieb']);
});

it('answers not found for a team subdomain no top level team carries', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);
    $this->teamRows = [];

    expect(fn () => app(WelcomeTenantResolver::class)->resolve(($this->request)('https://nobody.acme.nubos.test/')))->toThrow(ModelNotFoundException::class);
});

it('finds the tenant of a site by its own domain that points at the platform', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);

    $address = app(WelcomeTenantResolver::class)->resolve(($this->request)('https://Chat.Acme.de/'));

    expect($address?->tenant->getKey())->toBe(ModelStub::ulid('acme'))
        ->and($address?->team)->toBeNull()
        ->and($this->connection->queries[0]['sql'])->toContain('"domain" = ?')->toContain('"deleted_at" is null')
        ->and($this->connection->queries[0]['bindings'])->toBe(['chat.acme.de'])
        ->and($this->provider->askedDomains)->toBe([]);
});

it('asks the site providers for an own domain no tenant claims and keeps the requested page', function (): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);
    $this->connection = StaticQueryConnection::install(static fn (): array => []);
    $tenant = ModelStub::make(Tenant::class, $this->tenantRow);
    $team = ModelStub::make(Team::class, $this->teamRow);
    $this->domainSite = new SiteAddress($tenant, $team);

    $address = app(WelcomeTenantResolver::class)->resolve(($this->request)('https://www.vertrieb-acme.de/kontakt'));

    expect($address?->tenant)->toBe($tenant)
        ->and($address?->team)->toBe($team)
        ->and($address?->path)->toBe('kontakt')
        ->and($this->provider->askedDomains)->toBe(['www.vertrieb-acme.de']);
});

it('shows the welcome page without a tenant on the platform domain or on a domain nobody claims', function (string $url, int $queries): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);
    $this->connection = StaticQueryConnection::install(static fn (): array => []);

    expect(app(WelcomeTenantResolver::class)->resolve(($this->request)($url)))->toBeNull()
        ->and($this->connection->queries)->toHaveCount($queries);
})->with([
    'platform domain' => ['https://nubos.test/', 0],
    'application address' => ['https://app.nubos.test/', 0],
    'unclaimed domain' => ['https://www.other.de/', 1],
    'address without a domain' => ['http://127.0.0.1/', 0],
]);

it('answers not found for a page below an address that carries no site', function (string $url): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);
    $this->connection = StaticQueryConnection::install(static fn (): array => []);

    expect(fn () => app(WelcomeTenantResolver::class)->resolve(($this->request)($url)))->toThrow(ModelNotFoundException::class);
})->with([
    'platform domain' => ['https://nubos.test/acme'],
    'unclaimed domain' => ['https://www.other.de/kontakt'],
]);

it('answers not found for a slug or subdomain no tenant carries', function (string $addressing, string $url): void {
    config(['tenancy.welcome.addressing' => $addressing]);
    StaticQueryConnection::install(static fn (): array => []);

    expect(fn () => app(WelcomeTenantResolver::class)->resolve(($this->request)($url)))->toThrow(ModelNotFoundException::class);
})->with([
    'unknown slug in the path' => ['path', 'http://localhost/nobody'],
    'unknown subdomain' => ['host', 'https://nobody.nubos.test/'],
]);

it('never asks the database for a subdomain nested deeper than a team or for a malformed label', function (string $url): void {
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value]);

    expect(fn () => app(WelcomeTenantResolver::class)->resolve(($this->request)($url)))->toThrow(ModelNotFoundException::class)
        ->and($this->connection->queries)->toBe([]);
})->with([
    'three levels' => ['https://a.b.c.nubos.test/'],
    'malformed team label' => ['https://-x.acme.nubos.test/'],
]);

it('names the site address of a tenant and of a top level team by path or by subdomain', function (): void {
    $tenant = ModelStub::make(Tenant::class, $this->tenantRow);
    $team = ModelStub::make(Team::class, $this->teamRow);
    $resolver = app(WelcomeTenantResolver::class);

    config(['tenancy.welcome.addressing' => WelcomeAddressing::Path->value, 'app.url' => 'http://localhost']);
    $tenantByPath = $resolver->addressOf($tenant);
    $teamByPath = $resolver->addressOf($tenant, $team);

    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value, 'app.url' => 'https://nubos.test']);

    expect($tenantByPath)->toBe('http://localhost/acme')
        ->and($teamByPath)->toBe('http://localhost/acme/vertrieb')
        ->and($resolver->addressOf($tenant))->toBe('https://acme.nubos.test')
        ->and($resolver->addressOf($tenant, $team))->toBe('https://vertrieb.acme.nubos.test')
        ->and($resolver->subdomainOf($tenant))->toBe('acme.nubos.test')
        ->and($resolver->subdomainOf($tenant, $team))->toBe('vertrieb.acme.nubos.test')
        ->and($resolver->isPlatformHost('nubos.test'))->toBeTrue()
        ->and($resolver->isPlatformHost('crm.nubos.test'))->toBeTrue()
        ->and($resolver->isPlatformHost('chat.acme.de'))->toBeFalse();
});
