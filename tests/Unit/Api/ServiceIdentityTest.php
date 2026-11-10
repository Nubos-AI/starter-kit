<?php

declare(strict_types=1);

use App\Actions\Api\ProvisionServiceUserAction;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks the service identity up by tenant and service flag instead of a guessable address', function (): void {
    $shape = QueryShape::attemptedBy(fn (): User => app(ProvisionServiceUserAction::class)->execute($this->tenant));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->isScopedToTenant('users', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasConjunctiveCondition('users', 'is_service'))->toBeTrue()
        ->and($shape->hasColumnCondition('users', 'email'))->toBeFalse()
        ->and($shape->bindings)->not->toContain('service-'.strtolower((string) $this->tenant->getKey()).'@service.invalid');
});

it('lets the database hold at most one live service identity per tenant', function (): void {
    expect(SchemaShape::ofMigration('database/migrations/0001_01_01_000000_create_users_table.php')
        ->has('CREATE UNIQUE INDEX unq_users_tenant_service ON users (tenant_id) WHERE is_service AND deleted_at IS NULL'))->toBeTrue();
});

it('flushes the memoized field visibility at every octane request boundary', function (): void {
    /** @var list<string> $flush */
    $flush = (array) config('octane.flush');

    expect($flush)->toContain(FieldVisibilityResolver::class);
});

it('records on every token the user it acts for and drops the token together with that user', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000043_create_personal_access_tokens_table.php');

    expect($schema->columnsOf('personal_access_tokens'))->toBe([
        'id', 'tokenable_type', 'tokenable_id', 'creator_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
    ])
        ->and($schema->columnDefinition('personal_access_tokens', 'creator_id'))->toContain('char(26) null')
        ->and($schema->hasForeignKey('personal_access_tokens', 'creator_id', 'users', 'cascade'))->toBeTrue();
});
