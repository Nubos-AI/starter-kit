<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->companiesId = ModelStub::ulid('companies');
    $this->compiler = AccessContext::recordRuleCompiler();

    $this->postalCodeRule = [
        $this->companiesId => [[[
            'combinator' => 'and',
            'conditions' => [
                ['field' => 'postal_code', 'operator' => 'startsWith', 'value' => '76'],
            ],
        ]]],
    ];
});

afterEach(function (): void {
    AccessContext::suspendRowAccess();
    AccessContext::forgetTenant();
    AccessContext::forgetTeam();
});

it('leaves the query untouched while enforcement is off', function (): void {
    AccessContext::suspendRowAccess();
    AccessContext::actAs(AccessContext::user($this->tenant));
    AccessContext::rowAccessRules($this->postalCodeRule);

    CustomRecord::query()->toSql();

    expect($this->compiler->calls)->toBeEmpty();
});

it('compiles the effective rules into the query once enforcement is on', function (): void {
    AccessContext::enforceRowAccess();
    AccessContext::actAs(AccessContext::user($this->tenant));
    AccessContext::rowAccessRules($this->postalCodeRule);

    CustomRecord::query()->toSql();

    expect($this->compiler->calls)->toHaveCount(1)
        ->and($this->compiler->calls[0]['table'])->toBe('custom_records')
        ->and($this->compiler->calls[0]['objectTypeIds'])->toBe([$this->companiesId]);
});

it('hands the rule compiler a query that is already scoped to the tenant', function (): void {
    AccessContext::enforceRowAccess();
    AccessContext::actAs(AccessContext::user($this->tenant));
    AccessContext::rowAccessRules($this->postalCodeRule);

    CustomRecord::query()->toSql();

    expect($this->compiler->calls[0]['shape']->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
});

it('does not restrict a request without an authenticated user', function (): void {
    AccessContext::enforceRowAccess();
    AccessContext::rowAccessRules($this->postalCodeRule);

    CustomRecord::query()->toSql();

    expect($this->compiler->calls)->toBeEmpty();
});

it('does not restrict a user whose effective rules are unrestricted', function (): void {
    AccessContext::enforceRowAccess();
    AccessContext::actAs(AccessContext::user($this->tenant));
    $resolver = AccessContext::unrestrictedRowAccess();

    CustomRecord::query()->toSql();

    expect($resolver->resolvedFor)->toHaveCount(1)
        ->and($this->compiler->calls)->toBeEmpty();
});

it('asks the resolver for the authenticated user, not for anyone else', function (): void {
    AccessContext::enforceRowAccess();
    $user = AccessContext::actAs(AccessContext::user($this->tenant, seed: 'member'));
    $resolver = AccessContext::rowAccessRules($this->postalCodeRule);

    CustomRecord::query()->toSql();

    expect($resolver->resolvedFor)->toBe([(string) $user->getKey()]);
});

it('restricts a suspended enforcement window back to unrestricted', function (): void {
    AccessContext::enforceRowAccess();
    AccessContext::actAs(AccessContext::user($this->tenant));
    AccessContext::rowAccessRules($this->postalCodeRule);

    app(RowAccessEnforcement::class)->withoutEnforcement(
        static fn (): string => CustomRecord::query()->toSql(),
    );

    expect($this->compiler->calls)->toBeEmpty();
});
