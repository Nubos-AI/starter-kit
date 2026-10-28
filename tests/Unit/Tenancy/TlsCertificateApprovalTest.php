<?php

declare(strict_types=1);

use App\Contracts\Tenancy\PublicSiteProviderInterface;
use App\DTOs\Tenancy\SiteAddress;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Http\Controllers\Tenancy\TlsCertificateApprovalController;
use App\Models\Tenant;
use App\Support\Tenancy\PublicSiteProviders;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.welcome.addressing' => WelcomeAddressing::Host->value,
        'tenancy.welcome.base_domain' => 'nubos.test',
        'tenancy.edge.tls_ask_token' => 'edge-token',
        'app.url' => 'https://app.nubos.test',
    ]);

    $this->exists = true;
    $this->connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'exists') ? [['exists' => $this->exists]] : []);

    $this->ask = function (string $domain, ?string $token = 'edge-token'): int {
        $request = Request::create('/tls/ask', 'GET', array_filter(['domain' => $domain, 'token' => $token], static fn (?string $value): bool => $value !== null));

        return app(TlsCertificateApprovalController::class)($request)->getStatusCode();
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Mockery::close();
});

it('lets the edge proxy ask for a certificate without a session', function (): void {
    $route = RouteShape::named('tenancy.tls.ask');

    expect($route->uri())->toBe('tls/ask')
        ->and($route->methods())->toContain('GET')
        ->and($route->resolvedMiddleware())->not->toContain(StartSession::class);
});

it('approves the platform domain and the address of the application without asking the database', function (string $domain): void {
    expect(($this->ask)($domain))->toBe(200)
        ->and($this->connection->queries)->toBe([]);
})->with(['platform domain' => ['nubos.test'], 'application address' => ['APP.nubos.test.']]);

it('approves the subdomain of a living tenant', function (): void {
    expect(($this->ask)('acme.nubos.test'))->toBe(200)
        ->and($this->connection->queries[0]['sql'])->toContain('from "tenants"')->toContain('"slug" = ?')->toContain('"deleted_at" is null')
        ->and($this->connection->queries[0]['bindings'])->toBe(['acme']);
});

it('approves an own domain only once the tenant has verified it', function (): void {
    expect(($this->ask)('chat.acme.de'))->toBe(200)
        ->and($this->connection->queries[0]['sql'])->toContain('"domain" = ?')->toContain('"domain_verified_at" is not null')->toContain('"deleted_at" is null')
        ->and($this->connection->queries[0]['bindings'])->toBe(['chat.acme.de']);
});

it('approves the subdomain of a living top level team below the subdomain of its tenant', function (): void {
    expect(($this->ask)('vertrieb.acme.nubos.test'))->toBe(200)
        ->and($this->connection->queries)->toHaveCount(1)
        ->and($this->connection->queries[0]['sql'])->toContain('from "teams"')->toContain('"parent_team_id" is null')->toContain('"slug" = ?')->toContain('from "tenants"')->toContain('"deleted_at" is null')->not->toContain('1 = 0')
        ->and($this->connection->queries[0]['bindings'])->toBe(['vertrieb', 'acme']);
});

it('approves an own domain a site provider carries as verified', function (): void {
    $this->exists = false;
    $provider = Mockery::mock(PublicSiteProviderInterface::class);
    $provider->shouldReceive('siteOfDomain')->once()->with('www.vertrieb-acme.de')->andReturn(new SiteAddress(ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme'), 'slug' => 'acme'])));
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([$provider]));

    expect(($this->ask)('www.vertrieb-acme.de'))->toBe(200);
});

it('refuses a subdomain or own domain no tenant carries', function (string $domain): void {
    $this->exists = false;

    expect(($this->ask)($domain))->toBe(404);
})->with(['unknown subdomain' => ['nobody.nubos.test'], 'unverified or unknown own domain' => ['chat.other.de']]);

it('refuses a nested subdomain or a malformed name without asking the database', function (string $domain): void {
    expect(($this->ask)($domain))->toBe(404)
        ->and($this->connection->queries)->toBe([]);
})->with([
    'subdomain nested deeper than a team' => ['a.b.c.nubos.test'],
    'malformed team label' => ['-x.acme.nubos.test'],
    'malformed' => ['acme.nubos.test/../x'],
    'without top level domain' => ['localhost'],
]);

it('refuses every request that does not carry the token of the edge proxy', function (?string $token, string $configured): void {
    config(['tenancy.edge.tls_ask_token' => $configured]);

    expect(($this->ask)('nubos.test', $token))->toBe(404)
        ->and($this->connection->queries)->toBe([]);
})->with([
    'missing token' => [null, 'edge-token'],
    'wrong token' => ['guess', 'edge-token'],
    'no token configured' => ['', ''],
]);
