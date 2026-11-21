<?php

declare(strict_types=1);

use App\Support\Approvals\ApprovalWorkloadQuery;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('approval-presence-tenant');
    $this->user = AccessContext::user($this->tenant, [], 'approval-presence-user');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('answers without loading a single process while no approval of the tenant is pending', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [['exists' => false]]);

    $answer = app(ApprovalWorkloadQuery::class)->hasOpenFor($this->user);
    $shape = new QueryShape($connection->queries[0]['sql'], $connection->queries[0]['bindings']);

    expect($answer)->toBeFalse()
        ->and($connection->queries)->toHaveCount(1)
        ->and($shape->sql)->toStartWith('select exists(')
        ->and($shape->targets('approval_processes'))->toBeTrue()
        ->and($shape->isScopedToTenant('approval_processes', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"status" = ?')
        ->and($shape->hasBinding('pending'))->toBeTrue();
});

it('goes on to load the open work once an approval of the tenant is pending', function (): void {
    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_starts_with($sql, 'select exists(') ? [['exists' => true]] : []);

    app(ApprovalWorkloadQuery::class)->hasOpenFor($this->user);

    expect(count($connection->queries))->toBeGreaterThan(1);
});
