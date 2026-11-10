<?php

declare(strict_types=1);

use App\Actions\Tenancy\VerifyTenantDomainAction;
use App\Contracts\Tenancy\PublicSiteProviderInterface;
use App\Enums\Tenancy\DomainProofPurpose;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Exceptions\Tenancy\DnsLookupFailedException;
use App\Models\Tenant;
use App\Support\Tenancy\DnsLookup;
use App\Support\Tenancy\DomainProof;
use App\Support\Tenancy\PublicSiteProviders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    config(['tenancy.welcome.addressing' => WelcomeAddressing::Host->value, 'tenancy.welcome.base_domain' => 'nubos.test']);
    Carbon::setTestNow('2026-10-10 12:00:00');

    $this->tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('acme'), 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'chat.acme.de', 'domain_verified_at' => null]);
    $this->dns = Mockery::mock(DnsLookup::class);
    app()->instance(DnsLookup::class, $this->dns);
    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $this->proof = DomainProof::recordFor(DomainProofPurpose::Site, ModelStub::ulid('acme'), 'chat.acme.de');

    $this->rejection = function (): array {
        try {
            app(VerifyTenantDomainAction::class)->execute($this->tenant);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The domain was verified.');
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Carbon::setTestNow();
    Mockery::close();
});

it('verifies an own domain whose CNAME record points at the subdomain of the tenant', function (string $cname): void {
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andReturn($cname);
    $this->dns->shouldNotReceive('addressesOf');
    $this->dns->shouldNotReceive('txtOf');

    $verified = app(VerifyTenantDomainAction::class)->execute($this->tenant);

    expect($verified->domain_verified_at?->toIso8601String())->toBe('2026-10-10T12:00:00+00:00')
        ->and($this->connection->writtenSqlOf('tenants')[0])->toContain('update "tenants" set "domain_verified_at" = ?');
})->with(['exact' => ['acme.nubos.test'], 'upper case with trailing dot' => ['ACME.nubos.test.']]);

it('releases the unverified claims other tenants hold on the domain once it is verified', function (): void {
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andReturn('acme.nubos.test');

    app(VerifyTenantDomainAction::class)->execute($this->tenant);

    $release = collect($this->connection->writtenStatements)->first(static fn (array $query): bool => str_contains($query['sql'], '"domain" = ?') && str_contains($query['sql'], '"id" != ?'));

    expect($release['sql'] ?? null)->toContain('update "tenants" set "domain" = ?')->toContain('"domain_verified_at" is null')
        ->and($release['bindings'][0])->toBeNull()
        ->and(array_slice($release['bindings'], -2))->toBe(['chat.acme.de', ModelStub::ulid('acme')])
        ->and($this->connection->openedTransactions)->toBe(1);
});

it('supersedes the verified claim of another tenant whose domain no longer points at it and logs the release', function (): void {
    Log::spy();
    StaticQueryConnection::uninstall();
    $this->connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'select * from "tenants"') && str_contains($sql, '"domain_verified_at" is not null')
        ? [['id' => ModelStub::ulid('rival'), 'name' => 'Rival', 'slug' => 'rival', 'domain' => 'chat.acme.de', 'domain_verified_at' => '2025-01-01 08:00:00']]
        : [], static fn (): int => 1);
    $this->dns->shouldReceive('cnameOf')->twice()->with('chat.acme.de')->andReturn('acme.nubos.test');

    $verified = app(VerifyTenantDomainAction::class)->execute($this->tenant);

    $supersede = collect($this->connection->writtenStatements)->first(static fn (array $query): bool => in_array(ModelStub::ulid('rival'), $query['bindings'], true));

    expect($verified->domain_verified_at)->not->toBeNull()
        ->and($supersede['sql'] ?? null)->toContain('update "tenants" set "domain" = ?, "domain_verified_at" = ?')
        ->and(array_slice($supersede['bindings'], 0, 2))->toBe([null, null])
        ->and($this->connection->rolledBackStatements)->toBe([])
        ->and($this->connection->openedTransactions)->toBe(1);

    Log::shouldHaveReceived('info')->with('A lapsed verified own domain claim was superseded.', ['domain' => 'chat.acme.de', 'released_tenant_id' => ModelStub::ulid('rival')]);
});

it('refuses to supersede the verified claim of another tenant that still verifies', function (): void {
    StaticQueryConnection::uninstall();
    $this->connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'select * from "tenants"') && str_contains($sql, '"domain_verified_at" is not null')
        ? [['id' => ModelStub::ulid('rival'), 'name' => 'Rival', 'slug' => 'rival', 'domain' => 'chat.acme.de', 'domain_verified_at' => '2025-01-01 08:00:00']]
        : [], static fn (): int => 1);
    $this->dns->shouldReceive('cnameOf')->andReturnNull();
    $this->dns->shouldReceive('addressesOf')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('txtOf')->with('_nubos-site.chat.acme.de')->andReturn([
        $this->proof['value'],
        DomainProof::recordFor(DomainProofPurpose::Site, ModelStub::ulid('rival'), 'chat.acme.de')['value'],
    ]);

    expect(($this->rejection)())->toBe(['domain' => [__('i18n.backend.actions.tenancy.update_tenant_domain_action.domain_taken')]])
        ->and($this->connection->writtenStatements)->toBe([])
        ->and($this->tenant->domain_verified_at)->toBeNull();
});

it('keeps the verified claim of another tenant and refuses while its re-check cannot ask the name service', function (): void {
    StaticQueryConnection::uninstall();
    $this->connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'select * from "tenants"') && str_contains($sql, '"domain_verified_at" is not null')
        ? [['id' => ModelStub::ulid('rival'), 'name' => 'Rival', 'slug' => 'rival', 'domain' => 'chat.acme.de', 'domain_verified_at' => '2025-01-01 08:00:00']]
        : [], static fn (): int => 1);
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andReturnNull();
    $this->dns->shouldReceive('addressesOf')->with('chat.acme.de')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('addressesOf')->with('acme.nubos.test')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('txtOf')->once()->with('_nubos-site.chat.acme.de')->andReturn([$this->proof['value']]);
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andThrow(new DnsLookupFailedException('chat.acme.de'));

    expect(($this->rejection)())->toBe(['domain' => ['Der DNS-Eintrag konnte gerade nicht abgefragt werden. Bitte versuchen Sie es später erneut.']])
        ->and($this->connection->writtenStatements)->toBe([])
        ->and($this->tenant->domain_verified_at)->toBeNull();
});

it('lets the site providers release their lapsed claims on the domain and refuses while one of them still holds it', function (bool $isReleased): void {
    $provider = Mockery::mock(PublicSiteProviderInterface::class);
    $provider->shouldReceive('releaseLapsedClaims')->once()->with('chat.acme.de')->andReturn($isReleased);
    app()->instance(PublicSiteProviders::class, new PublicSiteProviders([$provider]));
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andReturn('acme.nubos.test');

    if ($isReleased) {
        expect(app(VerifyTenantDomainAction::class)->execute($this->tenant)->domain_verified_at)->not->toBeNull();

        return;
    }

    expect(($this->rejection)())->toBe(['domain' => [__('i18n.backend.actions.tenancy.update_tenant_domain_action.domain_taken')]])
        ->and($this->tenant->domain_verified_at)->toBeNull();
})->with(['every claim lapsed' => [true], 'a site still verifies' => [false]]);

it('answers a tenant that lost the race for a verified domain on the domain field instead of failing', function (): void {
    StaticQueryConnection::uninstall();
    $this->connection = StaticQueryConnection::install(static fn (): array => [], static function (string $sql): int {
        throw new UniqueConstraintViolationException('pgsql', $sql, [], new PDOException('duplicate key value violates unique constraint "idx_tenants_domain"'));
    });
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andReturn('acme.nubos.test');

    expect(($this->rejection)())->toBe(['domain' => [__('i18n.backend.actions.tenancy.update_tenant_domain_action.domain_taken')]]);
});

it('verifies an apex domain whose flattened addresses all belong to the platform and that carries the TXT proof of the tenant', function (): void {
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andReturnNull();
    $this->dns->shouldReceive('addressesOf')->with('chat.acme.de')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('addressesOf')->with('acme.nubos.test')->andReturn(['203.0.113.10', '2001:db8::10']);
    $this->dns->shouldReceive('txtOf')->once()->with('_nubos-site.chat.acme.de')->andReturn(['v=spf1 -all', $this->proof['value']]);

    expect(app(VerifyTenantDomainAction::class)->execute($this->tenant)->domain_verified_at)->not->toBeNull();
});

it('refuses an apex domain on the platform addresses whose TXT proof is missing or belongs to another tenant and withdraws an earlier verification', function (string $proofOwner): void {
    $this->tenant->setRawAttributes([...$this->tenant->getAttributes(), 'domain_verified_at' => '2026-10-01 08:00:00'], true);
    $this->dns->shouldReceive('cnameOf')->andReturnNull();
    $this->dns->shouldReceive('addressesOf')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('txtOf')->with('_nubos-site.chat.acme.de')->andReturn(
        $proofOwner === 'nobody' ? [] : [DomainProof::recordFor(DomainProofPurpose::Site, ModelStub::ulid($proofOwner), 'chat.acme.de')['value']],
    );

    expect(($this->rejection)())->toBe(['domain' => ["Die Domain zeigt auf die Plattform, aber der Nachweis fehlt noch. Bitte legen Sie den TXT-Eintrag _nubos-site.chat.acme.de mit dem Wert {$this->proof['value']} an und prüfen Sie erneut."]])
        ->and($this->tenant->domain_verified_at)->toBeNull()
        ->and($this->connection->writtenSqlOf('tenants')[0])->toContain('"domain_verified_at" = ?');
})->with(['no TXT record' => ['nobody'], 'TXT record of another tenant' => ['rival']]);

it('refuses an own domain that points elsewhere or nowhere on the domain field and withdraws an earlier verification', function (?string $cname, array $addresses): void {
    $this->tenant->setRawAttributes([...$this->tenant->getAttributes(), 'domain_verified_at' => '2026-10-01 08:00:00'], true);
    $this->dns->shouldReceive('cnameOf')->andReturn($cname);
    $this->dns->shouldReceive('addressesOf')->with('chat.acme.de')->andReturn($addresses);
    $this->dns->shouldReceive('addressesOf')->with('acme.nubos.test')->andReturn(['203.0.113.10']);

    expect(($this->rejection)())->toBe(['domain' => ['Die Domain zeigt noch nicht auf acme.nubos.test. Bitte legen Sie den CNAME-Eintrag an und prüfen Sie erneut.']])
        ->and($this->tenant->domain_verified_at)->toBeNull()
        ->and($this->connection->writtenSqlOf('tenants')[0])->toContain('"domain_verified_at" = ?');
})->with([
    'CNAME to another host' => ['shop.example.com', []],
    'no record at all' => [null, []],
    'address outside the platform' => [null, ['198.51.100.7']],
]);

it('refuses to verify while the tenant has no own domain and never asks the name service', function (): void {
    $this->tenant->setRawAttributes([...$this->tenant->getAttributes(), 'domain' => null], true);
    $this->dns->shouldNotReceive('cnameOf');

    expect(array_keys(($this->rejection)()))->toBe(['domain'])
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('tells the user to try again later when the name service cannot be asked and keeps the verification untouched', function (): void {
    $this->dns->shouldReceive('cnameOf')->andThrow(new DnsLookupFailedException('chat.acme.de'));

    expect(($this->rejection)())->toBe(['domain' => ['Der DNS-Eintrag konnte gerade nicht abgefragt werden. Bitte versuchen Sie es später erneut.']])
        ->and($this->connection->writtenStatements)->toBe([]);
});
