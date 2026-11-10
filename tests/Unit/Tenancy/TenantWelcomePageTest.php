<?php

declare(strict_types=1);

use App\Actions\Tenancy\UpdateTenantDomainAction;
use App\Actions\Tenancy\VerifyTenantDomainAction;
use App\Contracts\Tenancy\PublicSiteProviderInterface;
use App\Enums\Tenancy\DomainProofPurpose;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Http\Controllers\Tenancy\TenantWelcomePageController;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\CurrentTeamResolver;
use App\Support\Teams\TeamSegment;
use App\Support\Tenancy\DomainProof;
use App\Support\Tenancy\PublicSiteProviders;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    URL::defaults([TeamSegment::key() => 'nubos']);
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value, 'tenancy.welcome.base_domain' => 'nubos.test', 'app.url' => 'https://nubos.test']);

    $this->tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme'), 'name' => 'Acme', 'slug' => 'acme', 'domain' => null, 'domain_verified_at' => null]);
    $this->user = AccessContext::user($this->tenant);
    $this->user->setRelation('tenant', $this->tenant);
    $this->claimed = [];
    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'exists') ? [['exists' => $this->claimed !== []]] : [],
        static fn (): int => 1,
    );

    $this->request = function (array $input = [], string $method = 'GET'): Request {
        $request = Request::create('/probe', $method, $input);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->setUserResolver(fn (): User => $this->user);

        return $request;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('keeps an own domain on the tenant that only one living tenant may hold verified', function (): void {
    StaticQueryConnection::uninstall();
    config(['modules.models.'.Tenant::class => []]);
    $tenants = SchemaShape::ofMigration('database/migrations/0001_01_01_000004_create_tenants_table.php');

    expect($tenants->columnsOf('tenants'))->toBe(['id', 'name', 'slug', 'domain', 'domain_verified_at', 'deleted_at', 'created_at', 'updated_at'])
        ->and($tenants->columnDefinition('tenants', 'domain'))->toContain('varchar(253)')->toContain('null')
        ->and($tenants->hasPartialUniqueIndex('idx_tenants_domain', 'tenants', ['domain'], 'deleted_at IS NULL AND domain_verified_at IS NOT NULL'))->toBeTrue()
        ->and($tenants->columnDefinition('tenants', 'domain_verified_at'))->toContain('timestamp')->toContain('null')
        ->and(Tenant::query()->make()->getFillable())->toBe(['name', 'slug', 'domain', 'domain_verified_at']);
});

it('stores the own domain of a tenant in lower case without the trailing dot', function (): void {
    $saved = app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => ' Chat.Acme.DE. ']);

    expect($saved->domain)->toBe('chat.acme.de')
        ->and(collect($this->connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('update "tenants" set "domain" = ?');
});

it('withdraws the verification once the own domain changes, keeps it while the domain stays and lets a tenant give the domain up', function (string $domain, bool $isKept, ?string $expectedDomain): void {
    $this->tenant->setRawAttributes([...$this->tenant->getAttributes(), 'domain' => 'chat.acme.de', 'domain_verified_at' => '2026-10-01 08:00:00'], true);

    $saved = app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => $domain]);

    expect($saved->domain_verified_at !== null)->toBe($isKept)
        ->and($saved->domain)->toBe($expectedDomain);
})->with([
    'same domain' => ['Chat.Acme.de', true, 'chat.acme.de'],
    'other domain' => ['shop.acme.de', false, 'shop.acme.de'],
    'no domain' => ['', false, null],
]);

it('rejects an own domain that is malformed or belongs to the platform on the domain field', function (string $domain): void {

    try {
        app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => $domain]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['domain'])
            ->and($this->connection->writtenStatements)->toBe([]);

        return;
    }

    $this->fail('The domain was accepted.');
})->with([
    'malformed' => ['https://acme.de/chat'],
    'without top level domain' => ['localhost'],
    'platform domain' => ['nubos.test'],
    'platform subdomain' => ['other.nubos.test'],
]);

it('stores an own domain another tenant or site claims or holds verified and leaves the decision to the verification', function (): void {
    $this->claimed = ['other'];
    $provider = Mockery::mock(PublicSiteProviderInterface::class);
    $provider->shouldNotReceive('releaseLapsedClaims');
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([$provider]));

    $saved = app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => 'chat.acme.de']);

    expect($saved->domain)->toBe('chat.acme.de')
        ->and($saved->domain_verified_at)->toBeNull()
        ->and(collect($this->connection->queries)->contains(static fn (array $query): bool => str_contains($query['sql'], 'exists')))->toBeFalse();
});

it('guards the welcome page settings with the organisation permissions', function (): void {
    expect(RouteShape::named('engine.welcome-page.edit')->hasDeclaredMiddleware('permission:organisation.view'))->toBeTrue()
        ->and(RouteShape::named('engine.welcome-page.update')->hasDeclaredMiddleware('permission:organisation.update'))->toBeTrue()
        ->and(RouteShape::named('engine.welcome-page.update')->methods())->toContain('PUT')
        ->and(RouteShape::named('engine.welcome-page.verify')->hasDeclaredMiddleware('permission:organisation.update'))->toBeTrue()
        ->and(RouteShape::named('engine.welcome-page.verify')->methods())->toContain('POST');
});

it('hands the welcome page settings the address of the tenant and the target its own domain points at', function (): void {
    AccessContext::grant('organisation.view');

    $props = app(TenantWelcomePageController::class)->edit(($this->request)())->toResponse(($this->request)())->original->getData()['page']['props'];

    expect($props['address'])->toBe('https://acme.nubos.test')
        ->and($props['cnameTarget'])->toBe('acme.nubos.test')
        ->and($props['domain'])->toBeNull()
        ->and($props['domainVerifiedAt'])->toBeNull()
        ->and($props['domainProof'])->toBeNull()
        ->and($props['canUpdate'])->toBeFalse();
});

it('hands the welcome page settings the TXT record that proves the stored own domain belongs to the tenant', function (): void {
    AccessContext::grant('organisation.view');
    $this->tenant->setRawAttributes([...$this->tenant->getAttributes(), 'domain' => 'acme.de'], true);
    $proof = DomainProof::recordFor(DomainProofPurpose::Site, ModelStub::ulid('acme'), 'acme.de');

    $props = app(TenantWelcomePageController::class)->edit(($this->request)())->toResponse(($this->request)())->original->getData()['page']['props'];

    expect($props['domainProof'])->toBe(['recordName' => '_nubos-site.acme.de', 'recordValue' => $proof['value']]);
});

it('hands the welcome page settings the address and name of the active team', function (): void {
    AccessContext::grant('organisation.view');
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('company'), 'tenant_id' => $this->tenant->getKey(), 'parent_team_id' => ModelStub::ulid('nubos'), 'name' => 'Nubos Company', 'slug' => 'nubos-company']);
    $teams = Mockery::mock(CurrentTeamResolver::class);
    $teams->shouldReceive('resolve')->with($this->user)->andReturn($team);
    app()->instance(CurrentTeamResolver::class, $teams);

    $props = app(TenantWelcomePageController::class)->edit(($this->request)())->toResponse(($this->request)())->original->getData()['page']['props'];

    expect($props['team'])->toBe(['name' => 'Nubos Company', 'address' => 'https://nubos-company.acme.nubos.test']);
});

it('hands the welcome page settings no team address while no team is active', function (): void {
    AccessContext::grant('organisation.view');
    $teams = Mockery::mock(CurrentTeamResolver::class);
    $teams->shouldReceive('resolve')->andReturnNull();
    app()->instance(CurrentTeamResolver::class, $teams);

    $props = app(TenantWelcomePageController::class)->edit(($this->request)())->toResponse(($this->request)())->original->getData()['page']['props'];

    expect($props['team'])->toBeNull();
});

it('hands only the own domain to the action', function (): void {
    $action = Mockery::mock(UpdateTenantDomainAction::class);
    $action->shouldReceive('execute')->once()->with($this->tenant, ['domain' => 'chat.acme.de'])->andReturn($this->tenant);
    app()->instance(UpdateTenantDomainAction::class, $action);

    app(TenantWelcomePageController::class)->update(($this->request)(['domain' => 'chat.acme.de', 'slug' => 'taken', 'name' => 'Other'], 'PUT'));
});

it('hands the tenant of the acting user to the domain verification', function (): void {
    $action = Mockery::mock(VerifyTenantDomainAction::class);
    $action->shouldReceive('execute')->once()->with($this->tenant)->andReturn($this->tenant);
    app()->instance(VerifyTenantDomainAction::class, $action);

    app(TenantWelcomePageController::class)->verify(($this->request)([], 'POST'));
});
