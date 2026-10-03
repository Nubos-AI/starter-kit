<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Policies\Engine\CustomRecordPolicy;
use Tests\Support\AccessContext;
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

    $this->compiler = AccessContext::recordRuleCompiler();
});

afterEach(function (): void {
    AccessContext::suspendRowAccess();
    AccessContext::forgetTenant();
});

it('derives the ability from the slug of the object type the record belongs to', function (): void {
    $resolver = AccessContext::grant();
    AccessContext::unrestrictedRowAccess();

    expect(app(CustomRecordPolicy::class)->view($this->user, $this->record))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['companies.view']);
});

it('refuses view without the permission and never touches the database', function (): void {
    AccessContext::grant();
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    $policy = app(CustomRecordPolicy::class);

    expect(QueryShape::attemptedBy(fn (): bool => $policy->view($this->user, $this->record)))->toBeNull()
        ->and($policy->view($this->user, $this->record))->toBeFalse();
});

it('grants view on the permission alone while enforcement is off', function (): void {
    AccessContext::grant('companies.view');
    AccessContext::suspendRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    expect(app(CustomRecordPolicy::class)->view($this->user, $this->record))->toBeTrue();
});

it('grants view without a row check when no rule covers the object type', function (): void {
    AccessContext::grant('companies.view');
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules([ModelStub::ulid('contacts') => [[[]]]]);

    $policy = app(CustomRecordPolicy::class);

    expect($policy->view($this->user, $this->record))->toBeTrue()
        ->and($this->compiler->calls)->toBeEmpty();
});

it('re-checks the record against the compiled rules before granting view', function (): void {
    AccessContext::grant('companies.view');
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    $policy = app(CustomRecordPolicy::class);

    $shape = QueryShape::attemptedBy(fn (): bool => $policy->view($this->user, $this->record));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->isKeyedTo('custom_records', (string) $this->record->getKey()))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeFalse()
        ->and($this->compiler->calls)->toHaveCount(1)
        ->and($this->compiler->calls[0]['table'])->toBe('custom_records');
});

it('re-checks the record before granting update', function (): void {
    AccessContext::grant('companies.update');
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    $policy = app(CustomRecordPolicy::class);

    expect(QueryShape::attemptedBy(fn (): bool => $policy->update($this->user, $this->record)))->not->toBeNull();
});

it('re-checks the record before granting merge and demands both abilities', function (): void {
    $resolver = AccessContext::grant('companies.merge');
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    $policy = app(CustomRecordPolicy::class);

    expect($policy->merge($this->user, $this->record))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['companies.merge', 'companies.update']);
});

it('decides delete on the permission alone, without a row check', function (): void {
    AccessContext::grant('companies.delete');
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->coveringRules);

    $policy = app(CustomRecordPolicy::class);

    expect($policy->delete($this->user, $this->record))->toBeTrue()
        ->and($policy->restore($this->user, $this->record))->toBeTrue()
        ->and($policy->forceDelete($this->user, $this->record))->toBeTrue()
        ->and($this->compiler->calls)->toBeEmpty();
});
