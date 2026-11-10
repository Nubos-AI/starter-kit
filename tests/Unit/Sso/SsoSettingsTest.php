<?php

declare(strict_types=1);

use App\Actions\Scim\RotateScimTokenAction;
use App\Actions\Sso\StartSsoLoginTestAction;
use App\Actions\Sso\TestSsoConnectionAction;
use App\Actions\Sso\UpdateSsoConnectionAction;
use App\DTOs\Sso\OidcProviderMetadata;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Ui\ToastType;
use App\Exceptions\Sso\OidcProviderException;
use App\Http\Controllers\Sso\SsoSettingsController;
use App\Models\Role;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\User;
use App\Policies\Sso\SsoConnectionPolicy;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Sso\OidcProviderClient;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\ValidationException;
use Inertia\Support\Header;
use Inertia\Support\SessionKey;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->tenant = AccessContext::tenant('acme');
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant));
    $this->user->setRelation('tenant', $this->tenant);

    $this->stored = null;
    $this->claimedElsewhere = false;
    $this->verifiedDomains = [];
    $this->roleExists = true;
    $this->scimTokenRow = null;
    $this->connection = StaticQueryConnection::install(
        function (string $sql): array {
            if (str_contains($sql, 'from "sso_verified_domains"') && str_contains($sql, '"sso_verified_domains"."tenant_id" != ?')) {
                return [['exists' => $this->claimedElsewhere]];
            }

            if (str_contains($sql, 'from "sso_verified_domains"')) {
                return array_map(fn (string $domain): array => ['id' => ModelStub::ulid($domain), 'tenant_id' => $this->tenant->getKey(), 'sso_connection_id' => ModelStub::ulid('connection'), 'domain' => $domain], $this->verifiedDomains);
            }

            if (str_contains($sql, 'from "sso_connections"')) {
                return $this->stored === null ? [] : [$this->stored];
            }

            if (str_contains($sql, 'from "scim_tokens"')) {
                return $this->scimTokenRow === null ? [] : [$this->scimTokenRow];
            }

            if (str_contains($sql, 'count(*) as "aggregate" from "roles"')) {
                return [['aggregate' => $this->roleExists ? 1 : 0]];
            }

            if (str_contains($sql, 'from "roles"') && str_contains($sql, 'limit 1')) {
                return [['id' => ModelStub::ulid('member-role'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'member', 'scope' => 'tenant', 'authority' => null]];
            }

            return [];
        },
        static fn (): int => 1,
    );
    app('validator')->setPresenceVerifier(new DatabasePresenceVerifier($this->connection));

    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    $this->subsetGuard->shouldReceive('assertMayAssignRole')->byDefault();
    app()->instance(PermissionSubsetGuard::class, $this->subsetGuard);

    $this->provider = Mockery::mock(OidcProviderClient::class);
    $this->provider->shouldReceive('metadata')->byDefault()->andReturnUsing(static fn (string $issuer): OidcProviderMetadata => new OidcProviderMetadata(rtrim($issuer, '/'), 'https://idp.acme.test/a', 'https://idp.acme.test/t', 'https://idp.acme.test/k', []));
    app()->instance(OidcProviderClient::class, $this->provider);

    $this->valid = static fn (array $overrides = []): array => [
        'is_enabled' => true,
        'is_enforced' => false,
        'is_jit_enabled' => true,
        'issuer_url' => 'https://idp.acme.test',
        'client_id' => 'client-acme',
        'client_secret' => 'client-secret',
        'allowed_domains' => [' ACME.de ', 'acme.com', '@acme.com'],
        'default_role_id' => ModelStub::ulid('member-role'),
        ...$overrides,
    ];

    $this->errorsOf = function (array $data): array {
        try {
            app(UpdateSsoConnectionAction::class)->execute($this->user, $data);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    $this->request = function (array $input = [], string $method = 'GET'): Request {
        $request = Request::create('/probe', $method, $input);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
        $request->setUserResolver(fn (): User => $this->user);

        return $request;
    };

    $this->writtenTo = fn (string $table): array => collect($this->connection->writtenStatements)
        ->filter(static fn (array $statement): bool => str_contains($statement['sql'], "\"{$table}\""))
        ->values()
        ->all();
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('guards every single sign-on settings route with the sso permission', function (string $name, string $method): void {
    expect(RouteShape::named($name)->hasDeclaredMiddleware('permission:sso.manage'))->toBeTrue()
        ->and(RouteShape::named($name)->methods())->toContain($method);
})->with([
    ['engine.sso.edit', 'GET'],
    ['engine.sso.update', 'PUT'],
    ['engine.sso.test', 'POST'],
    ['engine.sso.login-test', 'POST'],
    ['engine.sso.scim-token.rotate', 'POST'],
    ['engine.sso.domains.verify', 'POST'],
]);

it('throttles the routes that make the server call the identity provider', function (string $name): void {
    expect(RouteShape::named($name)->hasDeclaredMiddleware('throttle:sso'))->toBeTrue();
})->with(['engine.sso.update', 'engine.sso.test', 'engine.sso.login-test']);

it('lets every holder of the sso permission manage the connection, whether or not a role carries an authority', function (): void {
    $owner = RoleHolder::make(['tenant_id' => (string) $this->tenant->getKey()], [ModelStub::make(Role::class, ['authority' => RoleAuthority::SuperAdmin])], 'sso-owner');
    $admin = RoleHolder::make(['tenant_id' => (string) $this->tenant->getKey()], [ModelStub::make(Role::class, ['authority' => null])], 'sso-admin');

    AccessContext::grant('sso.manage');
    expect(app(SsoConnectionPolicy::class)->manage($owner))->toBeTrue()
        ->and(app(SsoConnectionPolicy::class)->manage($admin))->toBeTrue();

    AccessContext::grant('organisation.update');
    expect(app(SsoConnectionPolicy::class)->manage($owner))->toBeFalse()
        ->and(app(SsoConnectionPolicy::class)->manage($admin))->toBeFalse();
});

it('refuses every settings action before an action runs when the policy denies it', function (string $method): void {
    $spy = GateSpy::allowing();
    $update = Mockery::mock(UpdateSsoConnectionAction::class);
    $update->shouldNotReceive('execute');
    $rotate = Mockery::mock(RotateScimTokenAction::class);
    $rotate->shouldNotReceive('execute');
    $test = Mockery::mock(TestSsoConnectionAction::class);
    $test->shouldNotReceive('execute');
    $loginTest = Mockery::mock(StartSsoLoginTestAction::class);
    $loginTest->shouldNotReceive('execute');
    app()->instance(StartSsoLoginTestAction::class, $loginTest);
    app()->instance(UpdateSsoConnectionAction::class, $update);
    app()->instance(RotateScimTokenAction::class, $rotate);
    app()->instance(TestSsoConnectionAction::class, $test);

    expect(fn () => app(SsoSettingsController::class)->{$method}(($this->request)(['issuer_url' => 'https://idp.acme.test'], 'POST')))
        ->toThrow(AuthorizationException::class)
        ->and($spy->calls[0]['ability'])->toBe('manage')
        ->and($spy->calls[0]['arguments'])->toBe([SsoConnection::class]);
})->with(['edit', 'update', 'test', 'startLoginTest', 'rotateScimToken', 'verifyDomain']);

it('hands the page the connection without the client secret, the tenant roles and the addresses to register', function (): void {
    GateSpy::allowing('manage');
    $this->stored = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => true, 'is_jit_enabled' => false, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('very-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => null];

    $request = ($this->request)();
    $request->session()->put('scimTokenSecret', 'scim_plain');
    $props = app(SsoSettingsController::class)->edit($request)->toResponse($request)->original->getData()['page']['props'];

    expect($props['connection'])->toBe([
        'isEnabled' => true,
        'isEnforced' => true,
        'isJitEnabled' => false,
        'issuerUrl' => 'https://idp.acme.test',
        'clientId' => 'client-acme',
        'hasClientSecret' => true,
        'allowedDomains' => ['acme.de'],
        'verifiedDomains' => [],
        'domainProofs' => [['domain' => 'acme.de', 'recordName' => '_nubos-sso.acme.de', 'recordValue' => ModelStub::make(SsoConnection::class, $this->stored)->domainProofRecord('acme.de')['value']]],
        'defaultRoleId' => null,
    ])
        ->and($props['loginProof'])->toBe([
            'status' => 'missing',
            'verifiedAt' => null,
            'canTest' => true,
            'testReason' => null,
            'canEnforce' => true,
            'enforceReason' => null,
        ])
        ->and(json_encode($props))->not->toContain('very-secret')
        ->and($props['callbackUrl'])->toBe(route('sso.callback'))
        ->and($props['scim']['baseUrl'])->toBe(url('/scim/v2'))
        ->and($props['scim']['hasToken'])->toBeFalse()
        ->and($props['scim']['needsRotation'])->toBeFalse()
        ->and($props['scimToken'])->toBe('scim_plain')
        ->and($props['roleOptions'])->toBe([]);
});

it('tells the page to rotate a scim token that no entitled person stands behind any more', function (bool $isEntitled): void {
    GateSpy::allowing('manage');
    $this->scimTokenRow = ['id' => ModelStub::ulid('scim-token'), 'tenant_id' => $this->tenant->getKey(), 'created_by_id' => null, 'token_hash' => str_repeat('a', 64), 'last_used_at' => null, 'created_at' => '2026-10-01 08:00:00'];
    $guard = Mockery::mock(ScimAccessGuard::class);
    $guard->shouldReceive('hasEntitledCreator')->once()->with(Mockery::type(ScimToken::class))->andReturn($isEntitled);
    app()->instance(ScimAccessGuard::class, $guard);

    $request = ($this->request)();
    $props = app(SsoSettingsController::class)->edit($request)->toResponse($request)->original->getData()['page']['props'];

    expect($props['scim']['hasToken'])->toBeTrue()
        ->and($props['scim']['needsRotation'])->toBe(!$isEntitled);
})->with([
    'creator gone' => [false],
    'creator entitled' => [true],
]);

it('hands only the connection fields to the update action', function (): void {
    GateSpy::allowing('manage');
    $update = Mockery::mock(UpdateSsoConnectionAction::class);
    $update->shouldReceive('execute')->once()->with($this->user, ($this->valid)())->andReturn(new SsoConnection);
    app()->instance(UpdateSsoConnectionAction::class, $update);

    app(SsoSettingsController::class)->update(($this->request)([...($this->valid)(), 'tenant_id' => 'forged', 'id' => 'forged'], 'PUT'));
});

it('stores the connection with normalised domains, an encrypted secret and an audit entry without the secret', function (): void {
    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)());
    $insert = ($this->writtenTo)('sso_connections')[0];
    $audit = ($this->writtenTo)('audit_entries')[0];

    expect($saved->allowed_domains)->toBe(['acme.de', 'acme.com'])
        ->and($saved->client_secret)->toBe('client-secret')
        ->and($saved->tenant_id)->toBe((string) $this->tenant->getKey())
        ->and($insert['bindings'])->not->toContain('client-secret')
        ->and(json_encode($audit['bindings']))->not->toContain('client-secret')
        ->and(json_encode($audit['bindings']))->toContain('sso.connection_updated');
});

it('keeps the stored client secret when the form leaves it empty', function (): void {
    $this->stored = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => false, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('kept-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => null];

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['client_secret' => '']));

    expect($saved->client_secret)->toBe('kept-secret');
});

it('refuses an incomplete or unsafe connection on the field the form renders', function (array $overrides, string $field): void {
    expect(array_keys(($this->errorsOf)(($this->valid)($overrides))))->toContain($field)
        ->and($this->connection->writtenStatements)->toBe([]);
})->with([
    'enabled without issuer' => [['issuer_url' => ''], 'issuer_url'],
    'plain http issuer' => [['issuer_url' => 'http://idp.acme.test'], 'issuer_url'],
    'enabled without client id' => [['client_id' => ''], 'client_id'],
    'enabled without any secret' => [['client_secret' => ''], 'client_secret'],
    'enabled without domains' => [['allowed_domains' => []], 'allowed_domains'],
    'malformed domain' => [['allowed_domains' => ['not a domain']], 'allowed_domains'],
    'just in time without role' => [['default_role_id' => null], 'default_role_id'],
]);

it('stores the issuer the discovery document names instead of the entered spelling', function (): void {
    $this->provider->shouldReceive('metadata')->once()->with('https://idp.acme.test/')->andReturn(new OidcProviderMetadata('https://idp.acme.test', 'https://idp.acme.test/a', 'https://idp.acme.test/t', 'https://idp.acme.test/k', []));

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['issuer_url' => 'https://idp.acme.test/']));

    expect($saved->issuer_url)->toBe('https://idp.acme.test');
});

it('refuses to enable a connection whose issuer fails the discovery on the issuer field in german', function (OidcProviderException $failure, string $message): void {
    $this->provider->shouldReceive('metadata')->once()->andThrow($failure);

    expect(($this->errorsOf)(($this->valid)(['issuer_url' => 'https://login.microsoftonline.com/common/v2.0'])))->toBe(['issuer_url' => [$message]])
        ->and($this->connection->writtenStatements)->toBe([]);
})->with([
    'multi tenant entra' => [OidcProviderException::multiTenantIssuer(), 'Bitte die mandantenspezifische Aussteller-URL verwenden, z. B. https://login.microsoftonline.com/<Verzeichnis-ID>/v2.0.'],
    'invalid document' => [OidcProviderException::invalidDocument(), 'Unter dieser Adresse liegt kein gültiges OpenID-Connect-Discovery-Dokument für diesen Aussteller.'],
]);

it('stores a disabled draft connection without asking the identity provider', function (): void {
    $this->provider->shouldNotReceive('metadata');

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['is_enabled' => false, 'is_jit_enabled' => false, 'issuer_url' => 'https://idp.acme.test/']));

    expect($saved->issuer_url)->toBe('https://idp.acme.test/');
});

it('refuses a domain another tenant already proved', function (): void {
    $this->claimedElsewhere = true;

    expect(($this->errorsOf)(($this->valid)()))->toBe(['allowed_domains' => [__('i18n.backend.actions.sso.update_sso_connection_action.domain_taken', ['domain' => 'acme.de'])]]);
});

it('refuses a default role that is not a plain role of the tenant', function (): void {
    $this->roleExists = false;

    $errors = ($this->errorsOf)(($this->valid)());
    $check = collect($this->connection->queries)->first(static fn (array $entry): bool => str_contains($entry['sql'], 'from "roles"'));

    expect(array_keys($errors))->toBe(['default_role_id'])
        ->and($check['sql'])->toContain('"tenant_id" = ?')
        ->and($check['sql'])->toContain('"authority" is null')
        ->and($check['sql'])->toContain('"scope" = ?')
        ->and($check['bindings'])->toContain('tenant')
        ->and($check['bindings'])->toContain((string) $this->tenant->getKey());
});

it('reports a failing connection test on the issuer field in german', function (OidcProviderException $failure, string $key): void {
    $provider = Mockery::mock(OidcProviderClient::class);
    $provider->shouldReceive('metadata')->andThrow($failure);
    app()->instance(OidcProviderClient::class, $provider);

    try {
        app(TestSsoConnectionAction::class)->execute(['issuer_url' => 'https://idp.acme.test']);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['issuer_url' => [__("i18n.backend.actions.sso.test_sso_connection_action.{$key}")]]);

        return;
    }

    $this->fail('The test passed.');
})->with([
    'blocked address' => [OidcProviderException::blockedAddress(), 'blocked_address'],
    'plain http' => [OidcProviderException::insecureAddress(), 'insecure_address'],
    'invalid document' => [OidcProviderException::invalidDocument(), 'invalid_document'],
    'unreachable' => [OidcProviderException::unexpectedAnswer(), 'unreachable'],
    'multi tenant issuer' => [OidcProviderException::multiTenantIssuer(), 'multi_tenant_issuer'],
]);

it('hands the page the proof state, lets a missing proof block a new enforcement and explains why a login test cannot start', function (array $stored, array $expected): void {
    GateSpy::allowing('manage');
    $base = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => false, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('very-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => null, 'login_verified_at' => null, 'login_verified_fingerprint' => null];
    $fingerprint = ModelStub::make(SsoConnection::class, $base)->loginFingerprint();
    $this->stored = [...$base, ...array_map(static fn (mixed $value): mixed => $value === 'current' ? $fingerprint : $value, $stored)];

    $request = ($this->request)();
    $props = app(SsoSettingsController::class)->edit($request)->toResponse($request)->original->getData()['page']['props'];

    expect($props['loginProof'])->toBe($expected);
})->with([
    'never proven' => [[], ['status' => 'missing', 'verifiedAt' => null, 'canTest' => true, 'testReason' => null, 'canEnforce' => false, 'enforceReason' => 'Starten Sie zuerst eine erfolgreiche Testanmeldung. Erst danach lässt sich SSO erzwingen.']],
    'proven' => [['login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => 'current'], ['status' => 'verified', 'verifiedAt' => '2026-10-30T11:00:00+00:00', 'canTest' => true, 'testReason' => null, 'canEnforce' => true, 'enforceReason' => null]],
    'outdated proof' => [['login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => str_repeat('a', 64)], ['status' => 'outdated', 'verifiedAt' => '2026-10-30T11:00:00+00:00', 'canTest' => true, 'testReason' => null, 'canEnforce' => false, 'enforceReason' => 'Starten Sie zuerst eine erfolgreiche Testanmeldung. Erst danach lässt sich SSO erzwingen.']],
    'already enforced without proof' => [['is_enforced' => true], ['status' => 'missing', 'verifiedAt' => null, 'canTest' => true, 'testReason' => null, 'canEnforce' => true, 'enforceReason' => null]],
    'disabled' => [['is_enabled' => false], ['status' => 'missing', 'verifiedAt' => null, 'canTest' => false, 'testReason' => 'Speichern Sie zuerst eine aktive Verbindung mit Aussteller-URL, Client-ID und Client-Secret.', 'canEnforce' => false, 'enforceReason' => 'Starten Sie zuerst eine erfolgreiche Testanmeldung. Erst danach lässt sich SSO erzwingen.']],
]);

it('confirms a successful discovery only as found metadata and records no login proof', function (): void {
    GateSpy::allowing('manage');
    $this->provider->shouldReceive('metadata')->once()->with('https://idp.acme.test')->andReturn(new OidcProviderMetadata('https://idp.acme.test', 'https://idp.acme.test/a', 'https://idp.acme.test/t', 'https://idp.acme.test/k', []));

    app(SsoSettingsController::class)->test(($this->request)(['issuer_url' => 'https://idp.acme.test'], 'POST'));
    $toast = session()->get(SessionKey::FLASH_DATA)['toast'];

    expect($toast['type'])->toBe(ToastType::Info->value)
        ->and($toast['message'])->toBe('Discovery erfolgreich: Die Metadaten des Identitätsanbieters „https://idp.acme.test“ wurden gefunden. Client-ID, Client-Secret und die Anmeldung selbst sind damit nicht geprüft – starten Sie dafür eine Testanmeldung.')
        ->and($toast['message'])->not->toContain('Verbindung erfolgreich')
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('starts the login test for the acting user and leaves the page for the identity provider', function (): void {
    GateSpy::allowing('manage');
    $request = ($this->request)([], 'POST');
    $request->headers->set(Header::INERTIA, 'true');
    app()->instance('request', $request);
    $start = Mockery::mock(StartSsoLoginTestAction::class);
    $start->shouldReceive('execute')->once()->with($this->user, $request->session())->andReturn('https://idp.acme.test/authorize?state=x');
    app()->instance(StartSsoLoginTestAction::class, $start);

    $response = app(SsoSettingsController::class)->startLoginTest($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get(Header::LOCATION))->toBe('https://idp.acme.test/authorize?state=x');
});

it('refuses to enforce single sign-on on the enforce field while no login proof is current for the saved credentials', function (array $stored, array $overrides): void {
    $base = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => ModelStub::ulid('member-role'), 'login_verified_at' => null, 'login_verified_fingerprint' => null];
    $fingerprint = ModelStub::make(SsoConnection::class, $base)->loginFingerprint();
    $this->stored = [...$base, ...array_map(static fn (mixed $value): mixed => $value === 'current' ? $fingerprint : $value, $stored)];

    expect(($this->errorsOf)(($this->valid)(['is_enforced' => true, ...$overrides])))->toBe(['is_enforced' => [__('i18n.backend.actions.sso.update_sso_connection_action.enforce_requires_login_proof')]])
        ->and($this->connection->writtenStatements)->toBe([]);
})->with([
    'never proven' => [[], []],
    'proof of other credentials' => [['login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => str_repeat('a', 64)], []],
    'proven but the secret changes now' => [['login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => 'current'], ['client_secret' => 'rotated-secret']],
    'enforced before but the client id changes now' => [['is_enforced' => true, 'login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => 'current'], ['client_id' => 'client-other']],
]);

it('enforces single sign-on once a login proof is current and keeps an enforcement that predates the proof', function (array $stored): void {
    $base = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => ModelStub::ulid('member-role'), 'login_verified_at' => null, 'login_verified_fingerprint' => null];
    $fingerprint = ModelStub::make(SsoConnection::class, $base)->loginFingerprint();
    $this->stored = [...$base, ...array_map(static fn (mixed $value): mixed => $value === 'current' ? $fingerprint : $value, $stored)];

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['is_enforced' => true, 'client_secret' => '']));

    expect($saved->is_enforced)->toBeTrue();
})->with([
    'proven' => [['login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => 'current']],
    'enforced before the proof existed' => [['is_enforced' => true]],
]);

it('drops the login proof as soon as issuer, client id or client secret change', function (array $overrides): void {
    $base = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => ModelStub::ulid('member-role'), 'login_verified_at' => null, 'login_verified_fingerprint' => null];
    $fingerprint = ModelStub::make(SsoConnection::class, $base)->loginFingerprint();
    $this->stored = [...$base, 'login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => $fingerprint];

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['client_secret' => '', ...$overrides]));
    $update = ($this->writtenTo)('sso_connections')[0];

    expect($saved->login_verified_at)->toBeNull()
        ->and($saved->login_verified_fingerprint)->toBeNull()
        ->and($update['sql'])->toContain('"login_verified_at" = ?')
        ->and($update['sql'])->toContain('"login_verified_fingerprint" = ?');
})->with([
    'issuer' => [['issuer_url' => 'https://idp.other.test']],
    'client id' => [['client_id' => 'client-other']],
    'client secret' => [['client_secret' => 'rotated-secret']],
]);

it('keeps the login proof when only the domains or the provisioning change', function (): void {
    $base = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => ModelStub::ulid('member-role'), 'login_verified_at' => null, 'login_verified_fingerprint' => null];
    $fingerprint = ModelStub::make(SsoConnection::class, $base)->loginFingerprint();
    $this->stored = [...$base, 'login_verified_at' => '2026-10-30 11:00:00', 'login_verified_fingerprint' => $fingerprint];

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['client_secret' => '', 'is_jit_enabled' => false, 'default_role_id' => null]));

    expect($saved->hasCurrentLoginProof())->toBeTrue();
});

it('drops the proven domains as soon as issuer, client id or client secret change so a foreign identity provider cannot inherit them', function (array $overrides, array $expected): void {
    $this->stored = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de","acme.com"]', 'default_role_id' => ModelStub::ulid('member-role')];
    $this->verifiedDomains = ['acme.de', 'acme.com'];

    app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['client_secret' => '', ...$overrides]));
    $release = collect(($this->writtenTo)('sso_verified_domains'))->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "sso_verified_domains" set "deleted_at" = ?'));

    expect($release['sql'])->toContain('"sso_verified_domains"."sso_connection_id" = ?')
        ->and($release['bindings'])->toContain(ModelStub::ulid('connection'))
        ->and(array_values(array_intersect(['acme.de', 'acme.com'], $release['bindings'])))->toBe($expected);
})->with([
    'issuer' => [['issuer_url' => 'https://idp.other.test'], []],
    'client id' => [['client_id' => 'client-other'], []],
    'client secret' => [['client_secret' => 'rotated-secret'], []],
    'only the provisioning' => [['is_jit_enabled' => false, 'default_role_id' => null], ['acme.de', 'acme.com']],
]);

it('checks a newly chosen default role against the permissions of the acting user', function (): void {
    $this->subsetGuard->shouldReceive('assertMayAssignRole')->once()
        ->withArgs(static fn (Role $role): bool => $role->getKey() === ModelStub::ulid('member-role'));

    app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)());
});

it('refuses a default role that carries permissions beyond the own on the role field before anything is written', function (): void {
    $this->subsetGuard->shouldReceive('assertMayAssignRole')->once()->andThrow(new AuthorizationException('Sie können keine Berechtigungen vergeben, die Sie selbst nicht besitzen.'));

    expect(($this->errorsOf)(($this->valid)()))->toBe(['default_role_id' => ['Sie können keine Berechtigungen vergeben, die Sie selbst nicht besitzen.']])
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('leaves an unchanged default role alone so a connection stays saveable', function (): void {
    $this->stored = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => ModelStub::ulid('member-role')];
    $this->subsetGuard->shouldNotReceive('assertMayAssignRole');

    app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['client_secret' => '']));
});

it('rotates the scim token by retiring the previous one and storing only the hash of the new one', function (): void {
    $token = app(RotateScimTokenAction::class)->execute($this->user);
    $writes = ($this->writtenTo)('scim_tokens');

    expect($token)->toStartWith('scim_')
        ->and(strlen($token))->toBeGreaterThan(40)
        ->and($writes[0]['sql'])->toStartWith('update "scim_tokens" set "deleted_at" = ?')
        ->and($writes[0]['sql'])->toContain('"scim_tokens"."tenant_id" = ?')
        ->and($writes[1]['sql'])->toStartWith('insert into "scim_tokens"')
        ->and($writes[1]['bindings'])->toContain(hash('sha256', $token))
        ->and($writes[1]['bindings'])->not->toContain($token)
        ->and($writes[1]['sql'])->toContain('"created_by_id"')
        ->and($writes[1]['bindings'])->toContain((string) $this->user->getKey())
        ->and($writes[0]['bindings'])->toContain((string) $this->tenant->getKey());
});

it('shows a rotated scim token once through the session', function (): void {
    GateSpy::allowing('manage');
    $rotate = Mockery::mock(RotateScimTokenAction::class);
    $rotate->shouldReceive('execute')->once()->with($this->user)->andReturn('scim_plain');
    app()->instance(RotateScimTokenAction::class, $rotate);

    $request = ($this->request)([], 'POST');
    app(SsoSettingsController::class)->rotateScimToken($request);

    expect($request->session()->get('scimTokenSecret'))->toBe('scim_plain');
});

it('derives the domain proof from the stored challenge so two challenges never share a record', function (): void {
    $base = ['tenant_id' => (string) $this->tenant->getKey(), 'allowed_domains' => ['acme.de']];

    $first = ModelStub::make(SsoConnection::class, [...$base, 'domain_proof_nonce' => 'challenge-one'])->domainProofRecord('acme.de');
    $second = ModelStub::make(SsoConnection::class, [...$base, 'domain_proof_nonce' => 'challenge-two'])->domainProofRecord('acme.de');

    expect($first['name'])->toBe($second['name'])
        ->and($first['value'])->not->toBe($second['value']);
});

it('issues a fresh domain challenge and records the configurer whenever issuer, client id or client secret change', function (array $overrides): void {
    $this->stored = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'configured_by_id' => ModelStub::ulid('former-admin'), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => ModelStub::ulid('member-role'), 'domain_proof_nonce' => 'old-challenge'];
    $this->verifiedDomains = ['acme.de'];
    $before = ModelStub::make(SsoConnection::class, ['tenant_id' => (string) $this->tenant->getKey(), 'domain_proof_nonce' => 'old-challenge'])->domainProofRecord('acme.de');

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['client_secret' => '', ...$overrides]));

    expect($saved->domain_proof_nonce)->toBeString()
        ->and($saved->domain_proof_nonce)->not->toBe('old-challenge')
        ->and(strlen((string) $saved->domain_proof_nonce))->toBeGreaterThanOrEqual(32)
        ->and($saved->domainProofRecord('acme.de')['value'])->not->toBe($before['value'])
        ->and($saved->configured_by_id)->toBe((string) $this->user->getKey());
})->with([
    'issuer' => [['issuer_url' => 'https://idp.other.test']],
    'client id' => [['client_id' => 'client-other']],
    'client secret' => [['client_secret' => 'rotated-secret']],
]);

it('keeps the domain challenge and the configurer when only the domains or the provisioning change', function (): void {
    $this->stored = ['id' => ModelStub::ulid('connection'), 'tenant_id' => $this->tenant->getKey(), 'configured_by_id' => ModelStub::ulid('former-admin'), 'is_enabled' => true, 'is_enforced' => false, 'is_jit_enabled' => true, 'issuer_url' => 'https://idp.acme.test', 'client_id' => 'client-acme', 'client_secret' => encrypt('client-secret', false), 'allowed_domains' => '["acme.de"]', 'default_role_id' => ModelStub::ulid('member-role'), 'domain_proof_nonce' => 'old-challenge'];

    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)(['client_secret' => '', 'is_jit_enabled' => false, 'default_role_id' => null]));

    expect($saved->domain_proof_nonce)->toBe('old-challenge')
        ->and($saved->configured_by_id)->toBe(ModelStub::ulid('former-admin'));
});

it('gives a new connection a random domain challenge of its own', function (): void {
    $saved = app(UpdateSsoConnectionAction::class)->execute($this->user, ($this->valid)());

    expect($saved->domain_proof_nonce)->toBeString()
        ->and(strlen((string) $saved->domain_proof_nonce))->toBeGreaterThanOrEqual(32)
        ->and($saved->configured_by_id)->toBe((string) $this->user->getKey());
});
