<?php

declare(strict_types=1);

use App\Actions\Tenancy\VerifyTenantDomainAction;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Exceptions\Tenancy\DnsLookupFailedException;
use App\Models\Tenant;
use App\Support\Tenancy\DnsLookup;
use Illuminate\Support\Carbon;
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

    $verified = app(VerifyTenantDomainAction::class)->execute($this->tenant);

    expect($verified->domain_verified_at?->toIso8601String())->toBe('2026-10-10T12:00:00+00:00')
        ->and($this->connection->writtenSqlOf('tenants')[0])->toContain('update "tenants" set "domain_verified_at" = ?');
})->with(['exact' => ['acme.nubos.test'], 'upper case with trailing dot' => ['ACME.nubos.test.']]);

it('verifies an apex domain whose flattened addresses all belong to the platform', function (): void {
    $this->dns->shouldReceive('cnameOf')->once()->with('chat.acme.de')->andReturnNull();
    $this->dns->shouldReceive('addressesOf')->with('chat.acme.de')->andReturn(['203.0.113.10']);
    $this->dns->shouldReceive('addressesOf')->with('acme.nubos.test')->andReturn(['203.0.113.10', '2001:db8::10']);

    expect(app(VerifyTenantDomainAction::class)->execute($this->tenant)->domain_verified_at)->not->toBeNull();
});

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
