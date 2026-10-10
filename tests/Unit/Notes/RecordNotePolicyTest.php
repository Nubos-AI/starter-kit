<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordNote;
use App\Models\Role;
use App\Models\User;
use App\Policies\Notes\RecordNotePolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('note-tenant');
    $this->foreignTenantId = ModelStub::ulid('note-foreign-tenant');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('note-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('note-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->noteWith = fn (array $attributes = [], ?CustomRecord $record = null): RecordNote => ModelStub::make(RecordNote::class, [
        'id' => ModelStub::ulid('note'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->record->getKey(),
        'body' => 'Rückruf vereinbart.',
        ...$attributes,
    ], ['record' => $record ?? $this->record]);

    $this->allowViewFor = static function (string ...$userIds): void {
        $allowed = array_values($userIds);

        Gate::before(static function (?Authenticatable $user, string $ability) use ($allowed): ?bool {
            if ($ability !== 'view') {
                return null;
            }

            return in_array((string) $user?->getAuthIdentifier(), $allowed, true);
        });
    };

    $this->plainUser = fn (string $seed): User => RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], $seed);

    $this->escalatedUser = fn (string $seed): User => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [ModelStub::make(Role::class, [
            'id' => ModelStub::ulid('note-role'),
            'tenant_id' => $this->tenant->getKey(),
            'authority' => RoleAuthority::SuperAdmin->value,
        ])],
        $seed,
    );

    $this->policy = new RecordNotePolicy;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets a tenant member who may view the record read a note on it', function (): void {
    $reader = ($this->plainUser)('note-reader');
    ($this->allowViewFor)((string) $reader->getKey());

    expect($this->policy->view($reader, ($this->noteWith)()))->toBeTrue();
});

it('hides a note from a user who may not view the record behind it', function (): void {
    $blind = ($this->plainUser)('note-blind');
    ($this->allowViewFor)();

    expect($this->policy->view($blind, ($this->noteWith)()))->toBeFalse();
});

it('hides a note of another tenant even from a reader of the record', function (): void {
    $reader = ($this->plainUser)('note-reader');
    ($this->allowViewFor)((string) $reader->getKey());

    expect($this->policy->view($reader, ($this->noteWith)(['tenant_id' => $this->foreignTenantId])))->toBeFalse();
});

it('hides a note whose record is gone instead of raising a type error', function (): void {
    $reader = ($this->plainUser)('note-reader');
    ($this->allowViewFor)((string) $reader->getKey());

    $note = ModelStub::make(RecordNote::class, [
        'id' => ModelStub::ulid('note'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->record->getKey(),
    ], ['record' => null]);

    expect($this->policy->view($reader, $note))->toBeFalse()
        ->and($this->policy->update($reader, $note))->toBeFalse()
        ->and($this->policy->delete($reader, $note))->toBeFalse();
});

it('lets only the author change and delete an own note', function (): void {
    $author = ($this->plainUser)('note-author');
    $fellow = ($this->plainUser)('note-fellow');

    ($this->allowViewFor)((string) $author->getKey(), (string) $fellow->getKey());

    $note = ($this->noteWith)(['author_id' => $author->getKey()]);

    expect($this->policy->update($author, $note))->toBeTrue()
        ->and($this->policy->delete($author, $note))->toBeTrue()
        ->and($this->policy->update($fellow, $note))->toBeFalse()
        ->and($this->policy->delete($fellow, $note))->toBeFalse()
        ->and($this->policy->view($fellow, $note))->toBeTrue();
});

it('lets an escalated authority change and delete a foreign note', function (): void {
    $admin = ($this->escalatedUser)('note-admin');
    ($this->allowViewFor)((string) $admin->getKey());

    $note = ($this->noteWith)(['author_id' => ModelStub::ulid('note-author')]);

    expect($this->policy->update($admin, $note))->toBeTrue()
        ->and($this->policy->delete($admin, $note))->toBeTrue();
});

it('refuses even the author once that author may no longer view the record', function (): void {
    $author = ($this->plainUser)('note-author');
    ($this->allowViewFor)();

    $note = ($this->noteWith)(['author_id' => $author->getKey()]);

    expect($this->policy->update($author, $note))->toBeFalse()
        ->and($this->policy->delete($author, $note))->toBeFalse();
});

it('refuses an escalated authority of another tenant', function (): void {
    $admin = ($this->escalatedUser)('note-admin');
    ($this->allowViewFor)((string) $admin->getKey());

    $note = ($this->noteWith)(['tenant_id' => $this->foreignTenantId, 'author_id' => $admin->getKey()]);

    expect($this->policy->update($admin, $note))->toBeFalse()
        ->and($this->policy->delete($admin, $note))->toBeFalse();
});

it('is the policy the note model answers with', function (): void {
    expect(Gate::getPolicyFor(RecordNote::class))->toBeInstanceOf(RecordNotePolicy::class);
});
