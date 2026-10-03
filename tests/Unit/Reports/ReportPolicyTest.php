<?php

declare(strict_types=1);

use App\Models\ObjectType;
use App\Models\Report;
use App\Models\Tenant;
use App\Policies\Reports\ReportPolicy;
use App\Support\Authorization\TenantBoundary;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deals'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'name' => 'Deals',
    ]);

    $this->policy = new ReportPolicy(new TenantBoundary);

    /** @var callable(array<string, mixed>, ?ObjectType):Report */
    $this->report = fn (array $overrides = [], ?ObjectType $objectType = null): Report => ModelStub::make(
        Report::class,
        [
            'id' => ModelStub::ulid('report'),
            'tenant_id' => $this->tenant->getKey(),
            'owner_id' => ModelStub::ulid('owner'),
            'object_type_id' => $this->objectType->getKey(),
            ...$overrides,
        ],
        ['objectType' => $objectType ?? $this->objectType],
    );

    /** @var callable(bool, array<string, mixed>):StaticAuthorityUser */
    $this->user = function (bool $escalated = false, array $overrides = []): StaticAuthorityUser {
        /** @var StaticAuthorityUser $user */
        $user = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid('user'),
            'tenant_id' => $this->tenant->getKey(),
            ...$overrides,
        ]);

        $user->escalated = $escalated;

        return $user;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to read a report whose object type the user may not view', function (): void {
    AccessContext::grant();

    expect($this->policy->view(($this->user)(), ($this->report)()))->toBeFalse();
});

it('lets any viewer of the object type read a report of another owner', function (): void {
    AccessContext::grant('deals.view');

    expect($this->policy->view(($this->user)(), ($this->report)()))->toBeTrue();
});

it('asks for the view permission of the object type the report is built on', function (): void {
    $resolver = AccessContext::grant('deals.view');

    $this->policy->view(($this->user)(), ($this->report)());

    expect($resolver->askedFor)->toBe(['deals.view']);
});

it('refuses a report of another tenant although its row was handed in', function (): void {
    AccessContext::grant('deals.view');

    $foreign = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid('other-tenant')]);

    $report = ($this->report)(['tenant_id' => $foreign->getKey()]);

    expect($this->policy->view(($this->user)(), $report))->toBeFalse()
        ->and($this->policy->update(($this->user)(true), $report))->toBeFalse()
        ->and($this->policy->delete(($this->user)(true), $report))->toBeFalse();
});

it('refuses a report whose object type relation is missing entirely', function (): void {
    AccessContext::grant('deals.view');

    $report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('report'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('owner'),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => null]);

    $reached = QueryShape::attemptedBy(fn (): bool => $this->policy->view(($this->user)(), $report));

    expect($this->policy->view(($this->user)(), $report))->toBeFalse()
        ->and($reached)->toBeNull();
});

it('lets only the owner govern a report', function (): void {
    AccessContext::grant('deals.view');

    $owner = ($this->user)(false, ['id' => ModelStub::ulid('owner')]);
    $stranger = ($this->user)();

    $report = ($this->report)();

    expect($this->policy->update($owner, $report))->toBeTrue()
        ->and($this->policy->delete($owner, $report))->toBeTrue()
        ->and($this->policy->update($stranger, $report))->toBeFalse()
        ->and($this->policy->delete($stranger, $report))->toBeFalse();
});

it('lets an escalated authority govern a report of another owner', function (): void {
    AccessContext::grant('deals.view');

    expect($this->policy->update(($this->user)(true), ($this->report)()))->toBeTrue()
        ->and($this->policy->delete(($this->user)(true), ($this->report)()))->toBeTrue();
});

it('denies an escalated authority the report whose object type stays closed to it', function (): void {
    AccessContext::grant();

    expect($this->policy->update(($this->user)(true), ($this->report)()))->toBeFalse()
        ->and($this->policy->delete(($this->user)(true), ($this->report)()))->toBeFalse();
});

it('admits the report list to every signed in user and narrows it per row instead', function (): void {
    AccessContext::grant();

    expect($this->policy->viewAny(($this->user)()))->toBeTrue();
});

it('accepts a create without an object type and asks for the permission once one is named', function (): void {
    $resolver = AccessContext::grant();

    expect($this->policy->create(($this->user)()))->toBeTrue()
        ->and($this->policy->create(($this->user)(), $this->objectType))->toBeFalse()
        ->and($resolver->askedFor)->toBe(['deals.view']);
});

it('accepts a create for an object type the user may view', function (): void {
    AccessContext::grant('deals.view');

    expect($this->policy->create(($this->user)(), $this->objectType))->toBeTrue();
});
