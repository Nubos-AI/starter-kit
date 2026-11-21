<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Role;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(string, ?RoleAuthority):Role */
    $this->role = fn (string $seed, ?RoleAuthority $authority = null): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'authority' => $authority?->value,
    ]);

    /** @var callable(string):FieldDefinition */
    $this->field = fn (string $key): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'is_encrypted' => false,
    ]);

    /** @var callable(FieldDefinition, Role, bool, bool):FieldPermission */
    $this->grant = fn (FieldDefinition $field, Role $role, bool $read, bool $write): FieldPermission => ModelStub::make(FieldPermission::class, [
        'id' => ModelStub::ulid('grant-'.$field->key.'-'.$role->name),
        'tenant_id' => $this->tenant->getKey(),
        'role_id' => (string) $role->getKey(),
        'field_definition_id' => (string) $field->getKey(),
        'can_read' => $read,
        'can_write' => $write,
    ]);

    $this->childTypeId = ModelStub::ulid('contacts');
    $this->relationshipTypeId = ModelStub::ulid('company-contacts');

    /** @var callable(string, FieldType, array<string, mixed>, ?string):FieldDefinition */
    $this->computed = fn (string $key, FieldType $type, array $config, ?string $objectTypeId = null): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $objectTypeId ?? $this->objectTypeId,
        'key' => $key,
        'field_type' => $type,
        'config' => $config,
        'is_encrypted' => false,
    ]);

    /** @var callable(list<FieldDefinition>, list<FieldPermission>, list<FieldDefinition>):FieldVisibilityResolver */
    $this->resolverOver = function (array $fields, array $grants, array $childFields = []): FieldVisibilityResolver {
        $this->directory = (new FakeAuthorizationDirectory)->withFieldGrantRows($grants);

        $lookup = StaticObjectTypeFieldLookup::carrying($this->objectTypeId, $fields)
            ->withFields($this->childTypeId, $childFields)
            ->withRelationshipTarget($this->relationshipTypeId, $this->childTypeId);

        app()->instance(ObjectTypeFieldLookup::class, $lookup);
        app()->instance(AuthorizationDirectory::class, $this->directory);
        app()->forgetInstance(FieldVisibilityResolver::class);

        return app()->make(FieldVisibilityResolver::class);
    };

    /** @var callable(list<Role>):RoleHolder */
    $this->holderOf = fn (array $roles): RoleHolder => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        $roles,
        'field-rights-actor',
    );
});

afterEach(function (): void {
    app()->forgetInstance(ObjectTypeFieldLookup::class);
    app()->forgetInstance(AuthorizationDirectory::class);
    AccessContext::forgetTenant();
});

it('leaves a field nobody holds a row for open for reading and writing', function (): void {
    $open = ($this->field)('revenue');

    $resolver = ($this->resolverOver)([$open], []);
    $user = ($this->holderOf)([($this->role)('sales')]);

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe(['revenue'])
        ->and($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe([]);
});

it('combines the field rows of every role the user holds into the readable and the forbidden keys', function (array $userRoleSeeds, array $rows, array $readable, array $forbiddenRead, array $forbiddenWrite): void {
    $restricted = ($this->field)('salary');

    $resolver = ($this->resolverOver)([$restricted], array_map(
        fn (array $row): FieldPermission => ($this->grant)($restricted, ($this->role)($row[0]), $row[1], $row[2]),
        $rows,
    ));

    $user = ($this->holderOf)(array_map(fn (string $seed): Role => ($this->role)($seed), $userRoleSeeds));

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe($readable)
        ->and($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe($forbiddenRead)
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe($forbiddenWrite);
})->with([
    'a row of another role restricts nothing' => [['sales'], [['finance', false, false]], ['salary'], [], []],
    'a row that keeps reading withholds writing alone' => [['sales'], [['sales', true, false]], ['salary'], [], ['salary']],
    'a row that revokes both withholds reading and writing' => [['sales'], [['sales', false, false]], [], ['salary'], ['salary']],
    'a second role without a row opens the field' => [['sales', 'finance'], [['sales', false, false]], ['salary'], [], []],
    'a second role keeping its rights by row opens the field' => [['sales', 'finance'], [['sales', false, false], ['finance', true, true]], ['salary'], [], []],
    'every role revoking keeps the field closed' => [['sales', 'support'], [['sales', false, false], ['support', false, false]], [], ['salary'], ['salary']],
    'the read of one role combines with the write of another' => [['sales', 'finance'], [['sales', true, false], ['finance', false, true]], ['salary'], [], []],
    'a user without any role is held by a row that revokes both' => [[], [['sales', false, false]], [], ['salary'], ['salary']],
    'a user without any role is held by a row that withholds writing' => [[], [['sales', true, false]], ['salary'], [], ['salary']],
    'a user without any role keeps a field whose rows keep every right' => [[], [['sales', true, true]], ['salary'], [], []],
]);

it('lets an escalated authority past every field right without asking for grants', function (): void {
    $restricted = ($this->field)('salary');
    $strangerRole = ($this->role)('finance');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, $strangerRole, false, false)]);
    $user = ($this->holderOf)([($this->role)('super-admin', RoleAuthority::SuperAdmin)]);

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe(['salary'])
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($this->directory->askedFor)->not->toContain('fieldGrantsOfFields');
});

it('keeps a scope admin past the field rights as well', function (): void {
    $restricted = ($this->field)('salary');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, ($this->role)('finance'), false, false)]);
    $user = ($this->holderOf)([($this->role)('scope-admin', RoleAuthority::ScopeAdmin)]);

    expect($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe([]);
});

it('resolves the field rights once per user and object type', function (): void {
    $restricted = ($this->field)('salary');
    $role = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, $role, true, false)]);
    $user = ($this->holderOf)([$role]);

    $resolver->readableFieldKeys($user, $this->objectTypeId);
    $resolver->forbiddenReadFieldKeys($user, $this->objectTypeId);
    $resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId);

    expect(array_filter($this->directory->askedFor, static fn (string $call): bool => $call === 'fieldGrantsOfFields'))
        ->toHaveCount(1);
});

it('resolves again when the roles of the user change', function (): void {
    $restricted = ($this->field)('salary');
    $denying = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$restricted], [($this->grant)($restricted, $denying, false, false)]);

    $before = $resolver->readableFieldKeys(($this->holderOf)([$denying]), $this->objectTypeId);
    $after = $resolver->readableFieldKeys(($this->holderOf)([$denying, ($this->role)('finance')]), $this->objectTypeId);

    expect($before)->toBe([])
        ->and($after)->toBe(['salary'])
        ->and(array_filter($this->directory->askedFor, static fn (string $call): bool => $call === 'fieldGrantsOfFields'))
        ->toHaveCount(2);
});

it('reports an object type without any field as fully open and fully empty', function (): void {
    $resolver = ($this->resolverOver)([], []);
    $user = ($this->holderOf)([($this->role)('sales')]);

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenWriteFieldKeys($user, $this->objectTypeId))->toBe([]);
});

it('hides a formula from a viewer who may not read a field the formula reads', function (): void {
    $salary = ($this->field)('salary');
    $bonus = ($this->computed)('bonus', FieldType::Computed, ['formula' => '{salary} * 2']);
    $label = ($this->computed)('label', FieldType::Computed, ['formula' => 'TODAY()']);
    $sales = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$salary, $bonus, $label], [($this->grant)($salary, $sales, false, false)]);
    $user = ($this->holderOf)([$sales]);

    expect($resolver->readableFieldKeys($user, $this->objectTypeId))->toBe(['label'])
        ->and($resolver->forbiddenReadFieldKeys($user, $this->objectTypeId))->toBe(['salary', 'bonus']);
});

it('keeps a formula readable for a viewer who may read every field it reads', function (): void {
    $salary = ($this->field)('salary');
    $bonus = ($this->computed)('bonus', FieldType::Computed, ['formula' => '{salary} * 2']);
    $sales = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$salary, $bonus], [($this->grant)($salary, $sales, true, false)]);

    expect($resolver->readableFieldKeys(($this->holderOf)([$sales]), $this->objectTypeId))->toBe(['salary', 'bonus']);
});

it('hides a formula that reads a hidden field only through another formula', function (): void {
    $salary = ($this->field)('salary');
    $bonus = ($this->computed)('bonus', FieldType::Computed, ['formula' => '{salary} * 2']);
    $total = ($this->computed)('total', FieldType::Computed, ['formula' => '{bonus} + 1']);
    $sales = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$total, $bonus, $salary], [($this->grant)($salary, $sales, false, false)]);

    expect($resolver->readableFieldKeys(($this->holderOf)([$sales]), $this->objectTypeId))->toBe([]);
});

it('hides a roll-up whose source field on the child type is hidden from the viewer', function (): void {
    $childSalary = ($this->computed)('salary', FieldType::Number, [], $this->childTypeId);
    $payroll = ($this->computed)('payroll', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'salary',
    ]);
    $sales = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$payroll], [($this->grant)($childSalary, $sales, false, false)], [$childSalary]);

    expect($resolver->readableFieldKeys(($this->holderOf)([$sales]), $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenReadFieldKeys(($this->holderOf)([$sales]), $this->objectTypeId))->toBe(['payroll']);
});

it('hides a roll-up that filters its children on a field hidden from the viewer', function (): void {
    $childSalary = ($this->computed)('salary', FieldType::Number, [], $this->childTypeId);
    $childCount = ($this->computed)('head_count', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'count',
        'filter' => ['combinator' => 'and', 'conditions' => [['field' => 'salary', 'operator' => 'greaterThan', 'value' => 100000]]],
    ]);
    $sales = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$childCount], [($this->grant)($childSalary, $sales, false, false)], [$childSalary]);

    expect($resolver->readableFieldKeys(($this->holderOf)([$sales]), $this->objectTypeId))->toBe([]);
});

it('keeps a roll-up readable when the viewer may read its source on the child type', function (): void {
    $childSalary = ($this->computed)('salary', FieldType::Number, [], $this->childTypeId);
    $payroll = ($this->computed)('payroll', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'salary',
    ]);
    $sales = ($this->role)('sales');

    $resolver = ($this->resolverOver)([$payroll], [($this->grant)($childSalary, ($this->role)('finance'), false, false)], [$childSalary]);

    expect($resolver->readableFieldKeys(($this->holderOf)([$sales]), $this->objectTypeId))->toBe(['payroll']);
});

it('hides a roll-up whose child type cannot be resolved', function (): void {
    $payroll = ($this->computed)('payroll', FieldType::Rollup, [
        'relationship_type_id' => ModelStub::ulid('unknown-relationship'),
        'aggregate' => 'sum',
        'source_field_key' => 'salary',
    ]);

    $resolver = ($this->resolverOver)([$payroll], []);

    expect($resolver->readableFieldKeys(($this->holderOf)([($this->role)('sales')]), $this->objectTypeId))->toBe([]);
});

it('hides formulas that reference each other instead of recursing forever', function (): void {
    $first = ($this->computed)('first', FieldType::Computed, ['formula' => '{second} + 1']);
    $second = ($this->computed)('second', FieldType::Computed, ['formula' => '{first} + 1']);

    $resolver = ($this->resolverOver)([$first, $second], []);

    expect($resolver->readableFieldKeys(($this->holderOf)([($this->role)('sales')]), $this->objectTypeId))->toBe([]);
});

it('keeps a roll-up readable on the child type after the parent type resolved it through a roll-up pointing back', function (): void {
    $backRelationshipTypeId = ModelStub::ulid('contact-companies');
    $revenue = ($this->computed)('revenue', FieldType::Number, []);
    $contactAges = ($this->computed)('contact_ages', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'age',
    ]);
    $age = ($this->computed)('age', FieldType::Number, [], $this->childTypeId);
    $companyRevenue = ($this->computed)('company_revenue', FieldType::Rollup, [
        'relationship_type_id' => $backRelationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'revenue',
    ], $this->childTypeId);

    $directory = new FakeAuthorizationDirectory;
    $lookup = StaticObjectTypeFieldLookup::carrying($this->objectTypeId, [$revenue, $contactAges])
        ->withFields($this->childTypeId, [$age, $companyRevenue])
        ->withRelationshipTarget($this->relationshipTypeId, $this->childTypeId)
        ->withRelationshipTarget($backRelationshipTypeId, $this->objectTypeId);

    app()->instance(ObjectTypeFieldLookup::class, $lookup);
    app()->instance(AuthorizationDirectory::class, $directory);
    app()->forgetInstance(FieldVisibilityResolver::class);

    $resolver = app()->make(FieldVisibilityResolver::class);
    $viewer = ($this->holderOf)([($this->role)('sales')]);

    expect($resolver->readableFieldKeys($viewer, $this->objectTypeId))->toBe(['revenue', 'contact_ages'])
        ->and($resolver->readableFieldKeys($viewer, $this->childTypeId))->toBe(['age', 'company_revenue']);
});

it('hides a roll-up from a viewer whose team access rules narrow the child type', function (): void {
    $childAmount = ($this->computed)('amount', FieldType::Number, [], $this->childTypeId);
    $pipeline = ($this->computed)('pipeline', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
    ]);
    $sales = ($this->role)('sales');

    AccessContext::rowAccessRules([$this->childTypeId => [[['combinator' => 'and', 'conditions' => []]]]]);
    AccessContext::enforceRowAccess();

    $resolver = ($this->resolverOver)([$pipeline], [], [$childAmount]);
    $viewer = ($this->holderOf)([$sales]);

    expect($resolver->readableFieldKeys($viewer, $this->objectTypeId))->toBe([])
        ->and($resolver->forbiddenReadFieldKeys($viewer, $this->objectTypeId))->toBe(['pipeline']);
});

it('hides a count roll-up that reads no child field once team access rules narrow the child type', function (): void {
    $headCount = ($this->computed)('head_count', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'count',
    ]);

    AccessContext::rowAccessRules([$this->childTypeId => [[['combinator' => 'and', 'conditions' => []]]]]);
    AccessContext::enforceRowAccess();

    $resolver = ($this->resolverOver)([$headCount], []);

    expect($resolver->readableFieldKeys(($this->holderOf)([($this->role)('sales')]), $this->objectTypeId))->toBe([]);
});

it('hides a formula that reads a roll-up narrowed by team access rules', function (): void {
    $childAmount = ($this->computed)('amount', FieldType::Number, [], $this->childTypeId);
    $pipeline = ($this->computed)('pipeline', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
    ]);
    $weighted = ($this->computed)('weighted', FieldType::Computed, ['formula' => '{pipeline} * 2']);
    $name = ($this->field)('name');

    AccessContext::rowAccessRules([$this->childTypeId => [[['combinator' => 'and', 'conditions' => []]]]]);
    AccessContext::enforceRowAccess();

    $resolver = ($this->resolverOver)([$name, $pipeline, $weighted], [], [$childAmount]);

    expect($resolver->readableFieldKeys(($this->holderOf)([($this->role)('sales')]), $this->objectTypeId))->toBe(['name']);
});

it('keeps a roll-up readable when the team access rules narrow only another object type', function (): void {
    $childAmount = ($this->computed)('amount', FieldType::Number, [], $this->childTypeId);
    $pipeline = ($this->computed)('pipeline', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
    ]);

    AccessContext::rowAccessRules([$this->objectTypeId => [[['combinator' => 'and', 'conditions' => []]]]]);
    AccessContext::enforceRowAccess();

    $resolver = ($this->resolverOver)([$pipeline], [], [$childAmount]);

    expect($resolver->readableFieldKeys(($this->holderOf)([($this->role)('sales')]), $this->objectTypeId))->toBe(['pipeline']);
});

it('keeps a roll-up readable while row access is not enforced for the run', function (): void {
    $childAmount = ($this->computed)('amount', FieldType::Number, [], $this->childTypeId);
    $pipeline = ($this->computed)('pipeline', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
    ]);

    $rules = AccessContext::rowAccessRules([$this->childTypeId => [[['combinator' => 'and', 'conditions' => []]]]]);
    AccessContext::suspendRowAccess();

    $resolver = ($this->resolverOver)([$pipeline], [], [$childAmount]);

    expect($resolver->readableFieldKeys(($this->holderOf)([($this->role)('sales')]), $this->objectTypeId))->toBe(['pipeline'])
        ->and($rules->resolvedFor)->toBe([]);
});

it('keeps a roll-up readable for an escalated authority under team access rules on the child type', function (): void {
    $childAmount = ($this->computed)('amount', FieldType::Number, [], $this->childTypeId);
    $pipeline = ($this->computed)('pipeline', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
    ]);

    AccessContext::rowAccessRules([$this->childTypeId => [[['combinator' => 'and', 'conditions' => []]]]]);
    AccessContext::enforceRowAccess();

    $resolver = ($this->resolverOver)([$pipeline], [], [$childAmount]);

    expect($resolver->readableFieldKeys(($this->holderOf)([($this->role)('super-admin', RoleAuthority::SuperAdmin)]), $this->objectTypeId))
        ->toBe(['pipeline']);
});

it('resolves the roll-up apart for a run that suspends row access inside an enforced request', function (): void {
    $childAmount = ($this->computed)('amount', FieldType::Number, [], $this->childTypeId);
    $pipeline = ($this->computed)('pipeline', FieldType::Rollup, [
        'relationship_type_id' => $this->relationshipTypeId,
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
    ]);

    AccessContext::rowAccessRules([$this->childTypeId => [[['combinator' => 'and', 'conditions' => []]]]]);
    $enforcement = AccessContext::enforceRowAccess();

    $resolver = ($this->resolverOver)([$pipeline], [], [$childAmount]);
    $viewer = ($this->holderOf)([($this->role)('sales')]);

    $enforced = $resolver->readableFieldKeys($viewer, $this->objectTypeId);
    $suspended = $enforcement->withoutEnforcement(fn (): array => $resolver->readableFieldKeys($viewer, $this->objectTypeId));

    expect($enforced)->toBe([])
        ->and($suspended)->toBe(['pipeline']);
});
