<?php

declare(strict_types=1);

use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Watchers\WatcherEligibility;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

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

    $this->action = app(SyncRecordCollaboratorsAction::class);
    $this->mate = ModelStub::ulid('mate');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a collaborator payload that carries no list at all', function (): void {
    expect(function (): void {
        $this->action->execute($this->record, []);
    })->toThrow(ValidationException::class);
});

it('refuses a collaborator payload that is no list of users', function (): void {
    expect(function (): void {
        $this->action->execute($this->record, ['collaborator_ids' => 'alle']);
    })->toThrow(ValidationException::class);
});

it('refuses a collaborator entry that is no identifier', function (): void {
    expect(function (): void {
        $this->action->execute($this->record, ['collaborator_ids' => ['nicht-ulid']]);
    })->toThrow(ValidationException::class);
});

it('checks every candidate against the tenant of the record and never against a service user', function (): void {
    $attempt = QueryShape::attemptedBy(function (): void {
        $this->action->execute($this->record, ['collaborator_ids' => [$this->mate]]);
    });

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('users'))->toBeTrue()
        ->and($attempt?->sql)->toContain('"tenant_id" = ?')
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->sql)->toContain('"is_service" = ?')
        ->and($attempt?->hidesSoftDeleted('users'))->toBeTrue()
        ->and($attempt?->hasBinding($this->mate))->toBeTrue();
});

it('asks nobody when the caller hands over an empty collaborator set', function (): void {
    $attempt = QueryShape::attemptedBy(function (): void {
        app(WatcherEligibility::class)->assertMayWatch($this->record, [], 'collaborator_ids', 'nicht erlaubt');
    });

    expect($attempt)->toBeNull();
});

it('asks for each named candidate only once even when the caller repeats it', function (): void {
    $attempt = QueryShape::attemptedBy(function (): void {
        $this->action->execute($this->record, ['collaborator_ids' => [$this->mate, $this->mate]]);
    });

    expect(array_filter($attempt?->bindings ?? [], fn (mixed $binding): bool => $binding === $this->mate))
        ->toHaveCount(1);
});
