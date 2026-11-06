<?php

declare(strict_types=1);

use App\Actions\Sso\UpdateSsoConnectionAction;
use App\Actions\Sso\VerifySsoDomainAction;
use App\DTOs\Sso\OidcProviderMetadata;
use App\Enums\Ui\ToastType;
use App\Exceptions\Tenancy\DnsLookupFailedException;
use App\Http\Controllers\Sso\SsoSettingsController;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Sso\OidcProviderClient;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Teams\TeamSegment;
use App\Support\Tenancy\DnsLookup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\ValidationException;
use Inertia\Support\SessionKey;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant));

    $this->stored = [
        'id' => ModelStub::ulid('connection'),
        'tenant_id' => $this->tenantId,
        'is_enabled' => true,
        'is_enforced' => false,
        'is_jit_enabled' => false,
        'issuer_url' => 'https://idp.acme.test',
        'client_id' => 'client-acme',
        'client_secret' => encrypt('client-secret', false),
        'allowed_domains' => '["acme.de","acme.com"]',
        'verified_domains' => '[]',
        'default_role_id' => null,
    ];
    $this->verifiedElsewhere = false;
    $this->connection = StaticQueryConnection::install(
        function (string $sql): array {
            if (str_contains($sql, 'from "sso_connections"') && str_contains($sql, 'exists')) {
                return [['exists' => $this->verifiedElsewhere]];
            }

            if (str_contains($sql, 'from "sso_connections"')) {
                return $this->stored === null ? [] : [$this->stored];
            }

            return [];
        },
        static fn (): int => 1,
    );
    app('validator')->setPresenceVerifier(new DatabasePresenceVerifier($this->connection));

    $this->dns = Mockery::mock(DnsLookup::class);
    app()->instance(DnsLookup::class, $this->dns);

    $this->proofOf = fn (string $domain): array => ModelStub::make(SsoConnection::class, $this->stored)->domainProofRecord($domain);

    $this->errorsOf = function (array $data): array {
        try {
            app(VerifySsoDomainAction::class)->execute($this->tenantId, $data);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The domain was verified.');
    };

    $this->writtenTo = fn (string $table): array => collect($this->connection->writtenStatements)
        ->filter(static fn (array $statement): bool => str_contains($statement['sql'], "\"{$table}\""))
        ->values()
        ->all();

    $this->request = function (array $input = [], string $method = 'POST'): Request {
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

it('derives a stable txt record per tenant and domain that another tenant cannot reuse', function (): void {
    $record = ($this->proofOf)('acme.de');
    $foreign = ModelStub::make(SsoConnection::class, [...$this->stored, 'tenant_id' => ModelStub::ulid('rival')])->domainProofRecord('acme.de');

    expect($record['name'])->toBe('_nubos-sso.acme.de')
        ->and($record['value'])->toBe('nubos-sso-verification='.hash_hmac('sha256', "sso-domain:{$this->tenantId}:acme.de", (string) config('app.key')))
        ->and(($this->proofOf)('acme.de'))->toBe($record)
        ->and(($this->proofOf)('acme.com')['value'])->not->toBe($record['value'])
        ->and($foreign['value'])->not->toBe($record['value']);
});

it('confirms a listed domain once its txt record carries the token of the tenant and records an audit entry', function (): void {
    $record = ($this->proofOf)('acme.de');
    $this->dns->shouldReceive('txtOf')->once()->with('_nubos-sso.acme.de')->andReturn(['v=spf1 -all', $record['value']]);

    $saved = app(VerifySsoDomainAction::class)->execute($this->tenantId, ['domain' => ' ACME.de ']);
    $update = ($this->writtenTo)('sso_connections')[0];
    $audit = ($this->writtenTo)('audit_entries')[0];

    expect($saved->verified_domains)->toBe(['acme.de'])
        ->and($update['sql'])->toContain('update "sso_connections" set "verified_domains" = ?')
        ->and($update['bindings'])->toContain('["acme.de"]')
        ->and(json_encode($audit['bindings']))->toContain('sso.domain_verified');
});

it('refuses a domain whose txt record is missing or carries a foreign token on the domain field and names the record', function (array $records): void {
    $record = ($this->proofOf)('acme.de');
    $this->dns->shouldReceive('txtOf')->once()->with('_nubos-sso.acme.de')->andReturn($records);

    expect(($this->errorsOf)(['domain' => 'acme.de']))->toBe(['allowed_domains' => [__('i18n.backend.actions.sso.verify_sso_domain_action.record_missing', ['domain' => 'acme.de', 'name' => $record['name'], 'value' => $record['value']])]])
        ->and(__('i18n.backend.actions.sso.verify_sso_domain_action.record_missing', ['domain' => 'acme.de', 'name' => $record['name'], 'value' => $record['value']]))->toContain($record['value'])
        ->and($this->connection->writtenStatements)->toBe([]);
})->with([
    'no record' => [[]],
    'token of another tenant' => [['nubos-sso-verification='.str_repeat('a', 64)]],
]);

it('refuses a domain the connection does not list without asking the name service', function (bool $hasConnection): void {
    $this->stored = $hasConnection ? $this->stored : null;
    $this->dns->shouldNotReceive('txtOf');

    expect(($this->errorsOf)(['domain' => 'victim.com']))->toBe(['allowed_domains' => [__('i18n.backend.actions.sso.verify_sso_domain_action.domain_not_listed', ['domain' => 'victim.com'])]])
        ->and($this->connection->writtenStatements)->toBe([]);
})->with([
    'listed nowhere on an existing connection' => [true],
    'no connection yet' => [false],
]);

it('refuses a domain another tenant already proved without asking the name service', function (): void {
    $this->verifiedElsewhere = true;
    $this->dns->shouldNotReceive('txtOf');

    expect(($this->errorsOf)(['domain' => 'acme.de']))->toBe(['allowed_domains' => [__('i18n.backend.actions.sso.verify_sso_domain_action.domain_taken', ['domain' => 'acme.de'])]])
        ->and($this->connection->writtenStatements)->toBe([]);

    $check = collect($this->connection->queries)->first(static fn (array $entry): bool => str_contains($entry['sql'], 'exists'));

    expect($check['sql'])->toContain('("sso_connections"."verified_domains")::jsonb @> ?')
        ->and($check['sql'])->toContain('"sso_connections"."tenant_id" != ?')
        ->and($check['bindings'])->toContain($this->tenantId);
});

it('reports an unreachable name service on the domain field in german', function (): void {
    $this->dns->shouldReceive('txtOf')->once()->andThrow(new DnsLookupFailedException('_nubos-sso.acme.de'));

    expect(($this->errorsOf)(['domain' => 'acme.de']))->toBe(['allowed_domains' => [__('i18n.backend.actions.sso.verify_sso_domain_action.dns_unavailable')]])
        ->and(__('i18n.backend.actions.sso.verify_sso_domain_action.dns_unavailable'))->toContain('Bitte versuchen Sie es')
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('refuses a request without a domain', function (): void {
    $this->dns->shouldNotReceive('txtOf');

    expect(array_keys(($this->errorsOf)([])))->toBe(['domain']);
});

it('guards and throttles the domain verification route', function (): void {
    expect(RouteShape::named('engine.sso.domains.verify')->hasDeclaredMiddleware('permission:sso.manage'))->toBeTrue()
        ->and(RouteShape::named('engine.sso.domains.verify')->hasDeclaredMiddleware('throttle:sso'))->toBeTrue()
        ->and(RouteShape::named('engine.sso.domains.verify')->methods())->toContain('POST');
});

it('refuses the domain verification before the action runs when the policy denies it', function (): void {
    $spy = GateSpy::allowing();
    $verify = Mockery::mock(VerifySsoDomainAction::class);
    $verify->shouldNotReceive('execute');
    app()->instance(VerifySsoDomainAction::class, $verify);

    expect(fn () => app(SsoSettingsController::class)->verifyDomain(($this->request)(['domain' => 'acme.de'])))
        ->toThrow(AuthorizationException::class)
        ->and($spy->calls[0]['ability'])->toBe('manage')
        ->and($spy->calls[0]['arguments'])->toBe([SsoConnection::class]);
});

it('hands only the domain to the verification and confirms it with a german toast', function (): void {
    GateSpy::allowing('manage');
    $verify = Mockery::mock(VerifySsoDomainAction::class);
    $verify->shouldReceive('execute')->once()->with($this->tenantId, ['domain' => 'acme.de'])->andReturn(new SsoConnection);
    app()->instance(VerifySsoDomainAction::class, $verify);

    app(SsoSettingsController::class)->verifyDomain(($this->request)(['domain' => 'acme.de', 'tenant_id' => 'forged']));
    $toast = session()->get(SessionKey::FLASH_DATA)['toast'];

    expect($toast['type'])->toBe(ToastType::Success->value)
        ->and($toast['message'])->toBe('Die Domain „acme.de“ ist bestätigt. Single Sign-on gilt ab sofort für ihre E-Mail-Adressen.');
});

it('hands the page the verified domains and the txt record of every listed domain', function (): void {
    GateSpy::allowing('manage');
    $this->stored = [...$this->stored, 'verified_domains' => '["acme.de"]'];

    $request = ($this->request)([], 'GET');
    $props = app(SsoSettingsController::class)->edit($request)->toResponse($request)->original->getData()['page']['props'];

    expect($props['connection']['verifiedDomains'])->toBe(['acme.de'])
        ->and($props['connection']['domainProofs'])->toBe([
            ['domain' => 'acme.de', 'recordName' => '_nubos-sso.acme.de', 'recordValue' => ($this->proofOf)('acme.de')['value']],
            ['domain' => 'acme.com', 'recordName' => '_nubos-sso.acme.com', 'recordValue' => ($this->proofOf)('acme.com')['value']],
        ]);
});

it('lets an email address claim the connection only for a proved domain', function (): void {
    $connection = ModelStub::make(SsoConnection::class, ['allowed_domains' => ['acme.de', 'victim.com'], 'verified_domains' => ['acme.de']]);

    expect($connection->claimsEmail('ada@ACME.de'))->toBeTrue()
        ->and($connection->claimsEmail('ada@victim.com'))->toBeFalse();
});

it('routes a login only to the connection that proved the domain', function (): void {
    StaticQueryConnection::uninstall();
    $query = QueryShape::attemptedBy(fn () => app(SsoConnectionLookup::class)->enabledForDomain('ACME.de'));

    expect($query->sql)->toContain('("sso_connections"."verified_domains")::jsonb @> ?')
        ->and($query->sql)->toContain('("sso_connections"."allowed_domains")::jsonb @> ?')
        ->and($query->hasBinding('"acme.de"'))->toBeTrue();
});

it('keeps only the proofs of domains that stay listed and lets only a proved foreign domain block a save', function (): void {
    $provider = Mockery::mock(OidcProviderClient::class);
    $provider->shouldReceive('metadata')->andReturn(new OidcProviderMetadata('https://idp.acme.test', 'https://idp.acme.test/a', 'https://idp.acme.test/t', 'https://idp.acme.test/k', []));
    app()->instance(OidcProviderClient::class, $provider);
    $this->stored = [...$this->stored, 'verified_domains' => '["acme.de","acme.com"]'];

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->tenantId, [
        'is_enabled' => true,
        'is_enforced' => false,
        'is_jit_enabled' => false,
        'issuer_url' => 'https://idp.acme.test',
        'client_id' => 'client-acme',
        'client_secret' => '',
        'allowed_domains' => ['acme.de', 'acme.net'],
        'default_role_id' => null,
    ]);
    $checks = collect($this->connection->queries)
        ->filter(static fn (array $entry): bool => str_contains($entry['sql'], 'exists'))
        ->values();

    expect($saved->verified_domains)->toBe(['acme.de'])
        ->and($checks)->toHaveCount(2)
        ->and($checks[0]['sql'])->toContain('("sso_connections"."verified_domains")::jsonb @> ?')
        ->and($checks[0]['sql'])->not->toContain('"allowed_domains"');
});
