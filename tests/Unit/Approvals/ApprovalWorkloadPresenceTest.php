<?php

declare(strict_types=1);

use App\Support\Approvals\ApprovalWorkloadQuery;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
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

    expect(app(ApprovalWorkloadQuery::class)->hasOpenFor($this->user))->toBeFalse()
        ->and($connection->queries)->toHaveCount(1)
        ->and($connection->queries[0]['sql'])->toStartWith('select exists(select * from "approval_processes"')
        ->and($connection->queries[0]['sql'])->toContain('"status" = ?')
        ->and($connection->queries[0]['bindings'])->toContain((string) $this->tenant->getKey(), 'pending');
});
