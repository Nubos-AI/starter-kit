<?php

declare(strict_types=1);

use App\Enums\Sso\IdentityProvider;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\UserIdentity;
use App\Policies\Sso\SsoConnectionPolicy;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Tests\Support\ModelStub;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('keeps one single sign-on connection per tenant with an encrypted client secret and the claimed domains as jsonb', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000074_create_sso_connections_table.php');

    expect($schema->columnsOf('sso_connections'))->toBe([
        'id', 'tenant_id', 'default_role_id', 'is_enabled', 'is_enforced', 'is_jit_enabled',
        'issuer_url', 'client_id', 'client_secret', 'allowed_domains', 'verified_domains', 'login_verified_at', 'login_verified_fingerprint',
        'deleted_at', 'created_at', 'updated_at',
    ])
        ->and($schema->columnDefinition('sso_connections', 'login_verified_at'))->toContain('timestamp')
        ->and($schema->columnDefinition('sso_connections', 'login_verified_at'))->toContain('null')
        ->and($schema->columnDefinition('sso_connections', 'login_verified_fingerprint'))->toContain('varchar(64)')
        ->and($schema->hasForeignKey('sso_connections', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($schema->hasForeignKey('sso_connections', 'default_role_id', 'roles', 'set null'))->toBeTrue()
        ->and($schema->columnDefinition('sso_connections', 'client_secret'))->toContain('text')
        ->and($schema->columnDefinition('sso_connections', 'allowed_domains'))->toContain('jsonb')
        ->and($schema->columnDefinition('sso_connections', 'verified_domains'))->toContain('jsonb')
        ->and($schema->columnDefinition('sso_connections', 'verified_domains'))->toContain("default '[]'")
        ->and($schema->has('CREATE INDEX idx_sso_connections_verified_domains ON sso_connections USING gin (verified_domains)'))->toBeTrue()
        ->and($schema->hasPartialUniqueIndex('unq_sso_connections_tenant', 'sso_connections', ['tenant_id'], 'deleted_at IS NULL'))->toBeTrue()
        ->and($schema->has('CREATE INDEX idx_sso_connections_allowed_domains ON sso_connections USING gin (allowed_domains)'))->toBeTrue()
        ->and(SchemaShape::ofMigration('database/migrations/0001_01_01_000074_create_sso_connections_table.php', 'down')->has('drop table if exists "sso_connections"'))->toBeTrue();
});

it('stores only the hash of the one living scim token of a tenant', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000075_create_scim_tokens_table.php');

    expect($schema->columnsOf('scim_tokens'))->toBe(['id', 'tenant_id', 'token_hash', 'last_used_at', 'deleted_at', 'created_at', 'updated_at'])
        ->and($schema->columnDefinition('scim_tokens', 'token_hash'))->toContain('varchar(64)')
        ->and($schema->has('alter table "scim_tokens" add constraint "scim_tokens_token_hash_unique" unique ("token_hash")'))->toBeTrue()
        ->and($schema->hasPartialUniqueIndex('unq_scim_tokens_tenant', 'scim_tokens', ['tenant_id'], 'deleted_at IS NULL'))->toBeTrue()
        ->and(SchemaShape::ofMigration('database/migrations/0001_01_01_000075_create_scim_tokens_table.php', 'down')->has('drop table if exists "scim_tokens"'))->toBeTrue();
});

it('links an external identity to exactly one user of the tenant', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000076_create_user_identities_table.php');

    expect($schema->columnsOf('user_identities'))->toBe(['id', 'tenant_id', 'user_id', 'provider', 'issuer', 'subject', 'deleted_at', 'created_at', 'updated_at'])
        ->and($schema->hasForeignKey('user_identities', 'user_id', 'users', 'cascade'))->toBeTrue()
        ->and($schema->columnDefinition('user_identities', 'provider'))->toContain('varchar(20)')
        ->and($schema->hasPartialUniqueIndex('unq_user_identities_tenant_provider_issuer_subject', 'user_identities', ['tenant_id', 'provider', 'issuer', 'subject'], 'deleted_at IS NULL'))->toBeTrue()
        ->and(SchemaShape::ofMigration('database/migrations/0001_01_01_000076_create_user_identities_table.php', 'down')->has('drop table if exists "user_identities"'))->toBeTrue();
});

it('fills the identity models in migration order, hides the secrets and scopes them to the tenant', function (): void {
    $connection = new SsoConnection;

    expect($connection->getFillable())->toBe(['tenant_id', 'default_role_id', 'is_enabled', 'is_enforced', 'is_jit_enabled', 'issuer_url', 'client_id', 'client_secret', 'allowed_domains', 'verified_domains', 'login_verified_at', 'login_verified_fingerprint'])
        ->and($connection->getHidden())->toBe(['client_secret'])
        ->and($connection->getCasts())->toMatchArray(['client_secret' => 'encrypted', 'allowed_domains' => 'array', 'verified_domains' => 'array', 'is_enforced' => 'boolean', 'login_verified_at' => 'datetime'])
        ->and((new ScimToken)->getFillable())->toBe(['tenant_id', 'token_hash', 'last_used_at'])
        ->and((new ScimToken)->getHidden())->toBe(['token_hash'])
        ->and((new UserIdentity)->getFillable())->toBe(['tenant_id', 'user_id', 'provider', 'issuer', 'subject'])
        ->and((new UserIdentity)->getCasts())->toMatchArray(['provider' => IdentityProvider::class]);

    foreach ([SsoConnection::class, ScimToken::class, UserIdentity::class] as $model) {
        $scopes = (new ReflectionClass($model))->getAttributes(ScopedBy::class)[0]->getArguments()[0];

        expect($scopes)->toBe([TenantScope::class]);
    }

    expect((new ReflectionClass(SsoConnection::class))->getAttributes(UsePolicy::class)[0]->getArguments()[0])->toBe(SsoConnectionPolicy::class);
});

it('normalises the claimed domains and tells whether an email address belongs to them', function (): void {
    $connection = ModelStub::make(SsoConnection::class, ['allowed_domains' => json_encode(['acme.de', 'acme.com']), 'verified_domains' => json_encode(['acme.de', 'acme.com'])]);

    expect($connection->claimsEmail('Ada@ACME.de'))->toBeTrue()
        ->and($connection->claimsEmail('ada@sub.acme.de'))->toBeFalse()
        ->and($connection->claimsEmail('ada@evil.test'))->toBeFalse()
        ->and($connection->claimsEmail('no-at-sign'))->toBeFalse();
});
