<?php

declare(strict_types=1);

use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Policies\Sso\SsoConnectionPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Tests\Support\ModelStub;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('keeps one single sign-on connection per tenant and indexes the claimed domains', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000074_create_sso_connections_table.php');

    expect($schema->hasForeignKey('sso_connections', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($schema->hasForeignKey('sso_connections', 'default_role_id', 'roles', 'set null'))->toBeTrue()
        ->and($schema->hasForeignKey('sso_connections', 'configured_by_id', 'users', 'set null'))->toBeTrue()
        ->and($schema->hasPartialUniqueIndex('unq_sso_connections_tenant', 'sso_connections', ['tenant_id'], 'deleted_at IS NULL'))->toBeTrue()
        ->and($schema->has('CREATE INDEX idx_sso_connections_allowed_domains ON sso_connections USING gin (allowed_domains)'))->toBeTrue()
        ->and($schema->has('CREATE INDEX idx_sso_connections_verified_domains ON sso_connections USING gin (verified_domains)'))->toBeFalse();
});

it('lets a verified single sign-on domain belong to one living connection across every tenant', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000074_create_sso_connections_table.php');

    expect($schema->hasForeignKey('sso_verified_domains', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($schema->hasForeignKey('sso_verified_domains', 'sso_connection_id', 'sso_connections', 'cascade'))->toBeTrue()
        ->and($schema->hasPartialUniqueIndex('unq_sso_verified_domains_domain', 'sso_verified_domains', ['domain'], 'deleted_at IS NULL'))->toBeTrue();
});

it('stores only the hash of the one living scim token of a tenant', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000075_create_scim_tokens_table.php');

    expect($schema->hasForeignKey('scim_tokens', 'created_by_id', 'users', 'set null'))->toBeTrue()
        ->and($schema->has('alter table "scim_tokens" add constraint "scim_tokens_token_hash_unique" unique ("token_hash")'))->toBeTrue()
        ->and($schema->hasPartialUniqueIndex('unq_scim_tokens_tenant', 'scim_tokens', ['tenant_id'], 'deleted_at IS NULL'))->toBeTrue();
});

it('links an external identity to exactly one user of the tenant', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000076_create_user_identities_table.php');

    expect($schema->hasForeignKey('user_identities', 'user_id', 'users', 'cascade'))->toBeTrue()
        ->and($schema->hasPartialUniqueIndex('unq_user_identities_tenant_provider_issuer_subject', 'user_identities', ['tenant_id', 'provider', 'issuer', 'subject'], 'deleted_at IS NULL'))->toBeTrue();
});

it('hides and encrypts the identity secrets and guards the connection with its policy', function (): void {
    $connection = new SsoConnection;

    expect($connection->getHidden())->toBe(['client_secret'])
        ->and($connection->getCasts())->toMatchArray(['client_secret' => 'encrypted'])
        ->and((new ScimToken)->getHidden())->toBe(['token_hash'])
        ->and((new ReflectionClass(SsoConnection::class))->getAttributes(UsePolicy::class)[0]->getArguments()[0])->toBe(SsoConnectionPolicy::class);
});

it('normalises the claimed domains and tells whether an email address belongs to them', function (): void {
    $connection = ModelStub::make(SsoConnection::class, ['allowed_domains' => json_encode(['acme.de', 'acme.com'])], ['verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, [['domain' => 'acme.de'], ['domain' => 'acme.com']])]);

    expect($connection->claimsEmail('Ada@ACME.de'))->toBeTrue()
        ->and($connection->claimsEmail('ada@sub.acme.de'))->toBeFalse()
        ->and($connection->claimsEmail('ada@evil.test'))->toBeFalse()
        ->and($connection->claimsEmail('no-at-sign'))->toBeFalse();
});
