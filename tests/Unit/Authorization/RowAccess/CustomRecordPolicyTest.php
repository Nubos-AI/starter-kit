<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Policies\Engine\CustomRecordPolicy;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->user = AccessContext::user($this->tenant);

    $this->coveringRules = [
        (string) $this->objectType->getKey() => [[[
            'combinator' => 'and',
            'conditions' => [
                ['field' => 'postal_code', 'operator' => 'startsWith', 'value' => '76'],
            ],
        ]]],
    ];

    AccessContext::recordRuleCompiler();
});

afterEach(function (): void {
    AccessContext::suspendRowAccess();
    AccessContext::forgetTenant();
});

it('refuses view without the permission derived from the object type slug and never touches the database', function (): void {
    $resolver = AccessContext::grant();
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    $policy = app(CustomRecordPolicy::class);
    $verdict = null;

    $attempted = QueryShape::attemptedBy(function () use ($policy, &$verdict): bool {
        $verdict = $policy->view($this->user, $this->record);

        return $verdict;
    });

    expect($attempted)->toBeNull()
        ->and($verdict)->toBeFalse()
        ->and($resolver->askedFor)->toBe(['companies.view']);
});

it('grants view on the permission alone while enforcement is off', function (): void {
    AccessContext::grant('companies.view');
    AccessContext::suspendRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    expect(app(CustomRecordPolicy::class)->view($this->user, $this->record))->toBeTrue();
});

it('refuses delete, restore and force delete of a record outside the row access rules', function (string $ability): void {
    AccessContext::grant('companies.delete');
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);
    StaticQueryConnection::install(static fn (): array => [['exists' => false]]);

    $verdict = app(CustomRecordPolicy::class)->{$ability}($this->user, $this->record);

    StaticQueryConnection::uninstall();

    expect($verdict)->toBeFalse();
})->with(['delete', 'restore', 'forceDelete']);
