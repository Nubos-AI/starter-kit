<?php

declare(strict_types=1);

use App\DTOs\Links\SourceLinkData;
use App\Handlers\Links\RecordSourceLinkResolver;
use App\Models\CustomRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('record-link-tenant');
    AccessContext::suspendRowAccess();
    URL::defaults(['activeTeam' => 'personal']);
    $this->viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'record-link-viewer'));
    $this->visibleId = ModelStub::ulid('record-link-visible');
    $this->hiddenId = ModelStub::ulid('record-link-hidden');
    $this->numberlessId = ModelStub::ulid('record-link-numberless');
    $this->objectTypeId = ModelStub::ulid('record-link-type');

    $this->rowFor = fn (string $id, ?string $number): array => [
        'id' => $id,
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'record_number' => $number,
        'deleted_at' => null,
    ];

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "custom_records"') => [
            ($this->rowFor)($this->visibleId, 'C-1'),
            ($this->rowFor)($this->hiddenId, 'C-2'),
            ($this->rowFor)($this->numberlessId, null),
        ],
        str_contains($sql, 'from "object_types"') => [[
            'id' => $this->objectTypeId,
            'tenant_id' => (string) $this->tenant->getKey(),
            'slug' => 'companies',
        ]],
        default => [],
    });

    Gate::before(fn (?Authenticatable $user, string $ability, array $arguments = []): bool => $ability === 'view'
        && ($arguments[0] ?? null) instanceof CustomRecord
        && $arguments[0]->getKey() !== $this->hiddenId);

    $this->resolver = app(RecordSourceLinkResolver::class);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('resolves every requested record in one query and keeps only those the viewer may open', function (): void {
    $links = $this->resolver->resolve($this->viewer, [$this->visibleId, $this->hiddenId, $this->numberlessId]);

    $recordQueries = $this->connection->sqlOf('custom_records');

    expect($recordQueries)->toHaveCount(1)
        ->and($recordQueries[0])->toContain('"custom_records"."id" in (?, ?, ?)')
        ->and(array_keys($links))->toBe([$this->visibleId, $this->numberlessId])
        ->and($links[$this->visibleId])->toBeInstanceOf(SourceLinkData::class)
        ->and($links[$this->visibleId]->label)->toBe('C-1')
        ->and($links[$this->visibleId]->href)->toBe(route('engine.records.show', ['record' => $this->visibleId]))
        ->and($links[$this->numberlessId]->label)->toBe('Datensatz');
});

it('asks the database nothing when there is nothing to resolve', function (): void {
    expect($this->resolver->resolve($this->viewer, []))->toBe([])
        ->and($this->connection->queries)->toBe([]);
});

it('calls a record accessible only when it exists and the viewer may open it', function (): void {
    expect($this->resolver->isAccessible($this->viewer, $this->visibleId))->toBeTrue();

    $this->connection->queries = [];

    StaticQueryConnection::uninstall();
    $empty = StaticQueryConnection::install(fn (): array => []);

    expect($this->resolver->isAccessible($this->viewer, $this->visibleId))->toBeFalse()
        ->and($empty->sqlOf('custom_records'))->toHaveCount(1);
});

it('refuses a record the viewer may not open', function (): void {
    StaticQueryConnection::uninstall();
    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "custom_records"') => [($this->rowFor)($this->hiddenId, 'C-2')],
        str_contains($sql, 'from "object_types"') => [['id' => $this->objectTypeId, 'tenant_id' => (string) $this->tenant->getKey(), 'slug' => 'companies']],
        default => [],
    });

    expect($this->resolver->isAccessible($this->viewer, $this->hiddenId))->toBeFalse();
});
