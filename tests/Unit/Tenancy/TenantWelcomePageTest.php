<?php

declare(strict_types=1);

use App\Actions\Tenancy\UpdateTenantDomainAction;
use App\Actions\Tenancy\VerifyTenantDomainAction;
use App\Contracts\Tenancy\PublicSiteProviderInterface;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Http\Controllers\Tenancy\TenantWelcomePageController;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Teams\TeamSegment;
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

it('keeps an own domain on the tenant that only one living tenant may claim', function (): void {
    StaticQueryConnection::uninstall();
    $tenants = SchemaShape::ofMigration('database/migrations/0001_01_01_000004_create_tenants_table.php');

    expect($tenants->columnsOf('tenants'))->toBe(['id', 'name', 'slug', 'domain', 'domain_verified_at', 'deleted_at', 'created_at', 'updated_at'])
        ->and($tenants->columnDefinition('tenants', 'domain'))->toContain('varchar(253)')->toContain('null')
        ->and($tenants->hasPartialUniqueIndex('idx_tenants_domain', 'tenants', ['domain'], 'deleted_at IS NULL'))->toBeTrue()
        ->and($tenants->columnDefinition('tenants', 'domain_verified_at'))->toContain('timestamp')->toContain('null')
        ->and(Tenant::query()->make()->getFillable())->toBe(['name', 'slug', 'domain', 'domain_verified_at']);
});

it('stores the own domain of a tenant in lower case without the trailing dot', function (): void {
    $saved = app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => ' Chat.Acme.DE. ']);

    expect($saved->domain)->toBe('chat.acme.de')
        ->and(collect($this->connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('update "tenants" set "domain" = ?');
});

it('withdraws the verification once the own domain changes and keeps it while the domain stays', function (string $domain, bool $isKept): void {
    $this->tenant->setRawAttributes([...$this->tenant->getAttributes(), 'domain' => 'chat.acme.de', 'domain_verified_at' => '2026-10-01 08:00:00'], true);

    $saved = app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => $domain]);

    expect($saved->domain_verified_at !== null)->toBe($isKept);
})->with([
    'same domain' => ['Chat.Acme.de', true],
    'other domain' => ['shop.acme.de', false],
    'no domain' => ['', false],
]);

it('lets a tenant give up its own domain', function (): void {
    $this->tenant->setRawAttributes([...$this->tenant->getAttributes(), 'domain' => 'chat.acme.de'], true);

    expect(app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => ''])->domain)->toBeNull();
});

it('rejects an own domain that is malformed, belongs to the platform or another tenant claims on the domain field', function (string $domain, bool $isClaimed): void {
    $this->claimed = $isClaimed ? ['other'] : [];

    try {
        app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => $domain]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['domain'])
            ->and($this->connection->writtenStatements)->toBe([]);

        return;
    }

    $this->fail('The domain was accepted.');
})->with([
    'malformed' => ['https://acme.de/chat', false],
    'without top level domain' => ['localhost', false],
    'platform domain' => ['nubos.test', false],
    'platform subdomain' => ['other.nubos.test', false],
    'claimed by another tenant' => ['chat.acme.de', true],
]);

it('rejects an own domain a site of a team already claims on the domain field', function (): void {
    $provider = Mockery::mock(PublicSiteProviderInterface::class);
    $provider->shouldReceive('claimsDomain')->once()->with('www.vertrieb-acme.de')->andReturnTrue();
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([$provider]));

    try {
        app(UpdateTenantDomainAction::class)->execute($this->tenant, ['domain' => 'www.vertrieb-acme.de']);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['domain' => [__('i18n.backend.actions.tenancy.update_tenant_domain_action.domain_taken')]])
            ->and($this->connection->writtenStatements)->toBe([]);

        return;
    }

    $this->fail('The domain was accepted.');
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
        ->and($props['canUpdate'])->toBeFalse();
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
