<?php

declare(strict_types=1);

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\Enums\Approvals\ApprovalExclusion;
use App\Models\CustomRecord;
use App\Support\Approvals\ApprovalExclusionResolver;
use App\Support\Governance\FieldEditorResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->triggerId = ModelStub::ulid('trigger');
    $this->editorId = ModelStub::ulid('editor');
    $this->creatorId = ModelStub::ulid('creator');
    $this->ownerId = ModelStub::ulid('owner');

    $this->editors = Mockery::mock(FieldEditorResolver::class);

    $this->resolver = fn (): ApprovalExclusionResolver => new ApprovalExclusionResolver($this->editors);

    $this->record = function (?string $ownerId = null): CustomRecord {
        return ModelStub::make(CustomRecord::class, [
            'id' => ModelStub::ulid('approval-record'),
            'tenant_id' => $this->tenant->getKey(),
            'owner_id' => $ownerId,
            'version' => 3,
        ]);
    };

    $this->allFourApply = function (): void {
        $this->editors->shouldReceive('lastEditorOfFields')->andReturn($this->editorId);
        $this->editors->shouldReceive('creatorOf')->andReturn($this->creatorId);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('enables every reason in the strict set', function (): void {
    $strict = ApprovalExclusionSet::strict();

    expect($strict->trigger)->toBeTrue()
        ->and($strict->lastEditor)->toBeTrue()
        ->and($strict->creator)->toBeTrue()
        ->and($strict->owner)->toBeTrue();

    foreach (ApprovalExclusion::cases() as $reason) {
        expect($strict->includes($reason))->toBeTrue();
    }
});

it('treats an absent and a null configuration entry as enabled and only an explicit false as disabled', function (): void {
    expect(ApprovalExclusionSet::fromArray([])->toArray())
        ->toBe(['trigger' => true, 'last_editor' => true, 'creator' => true, 'owner' => true])
        ->and(ApprovalExclusionSet::fromArray(['trigger' => null])->trigger)->toBeTrue()
        ->and(ApprovalExclusionSet::fromArray(['trigger' => false])->trigger)->toBeFalse()
        ->and(ApprovalExclusionSet::fromArray(['owner' => 0])->owner)->toBeFalse();
});

it('labels every exclusion reason in german', function (): void {
    expect(ApprovalExclusion::Trigger->label())->toBe('Auslöser des Vorgangs')
        ->and(ApprovalExclusion::LastEditor->label())->toBe('Letzter Bearbeiter eines geprüften Feldes')
        ->and(ApprovalExclusion::Creator->label())->toBe('Ersteller des Datensatzes')
        ->and(ApprovalExclusion::Owner->label())->toBe('Eigentümer des Datensatzes');
});

it('excludes the trigger, the last editor, the creator and the owner', function (): void {
    ($this->allFourApply)();

    $excluded = ($this->resolver)()->excludedUserIds(
        ($this->record)($this->ownerId),
        ApprovalExclusionSet::strict(),
        $this->triggerId,
        ['amount'],
    );

    expect($excluded)->toEqualCanonicalizing([$this->triggerId, $this->editorId, $this->creatorId, $this->ownerId]);
});

it('does not exclude a deactivated reason and never asks the editor resolver for it', function (): void {
    $this->editors->shouldNotReceive('lastEditorOfFields');
    $this->editors->shouldReceive('creatorOf')->andReturn($this->creatorId);

    $excluded = ($this->resolver)()->excludedUserIds(
        ($this->record)($this->ownerId),
        new ApprovalExclusionSet(trigger: false, lastEditor: false, creator: true, owner: true),
        $this->triggerId,
        ['amount'],
    );

    expect($excluded)->toEqualCanonicalizing([$this->creatorId, $this->ownerId])
        ->and($excluded)->not->toContain($this->triggerId);
});

it('drops the last editor reason when the resolver finds no editor at all', function (): void {
    $this->editors->shouldReceive('lastEditorOfFields')->andReturn(null);
    $this->editors->shouldReceive('creatorOf')->andReturn(null);

    $excluded = ($this->resolver)()->excludedUserIds(
        ($this->record)(null),
        ApprovalExclusionSet::strict(),
        $this->triggerId,
        ['amount'],
    );

    expect($excluded)->toBe([$this->triggerId]);
});

it('reports every matching reason for one person', function (): void {
    $everyone = ModelStub::ulid('one-person');

    $this->editors->shouldReceive('lastEditorOfFields')->andReturn($everyone);
    $this->editors->shouldReceive('creatorOf')->andReturn($everyone);

    $reasons = ($this->resolver)()->reasonsFor(
        $everyone,
        ($this->record)($everyone),
        ApprovalExclusionSet::strict(),
        $everyone,
        ['amount'],
    );

    expect($reasons)->toBe([
        ApprovalExclusion::Trigger,
        ApprovalExclusion::LastEditor,
        ApprovalExclusion::Creator,
        ApprovalExclusion::Owner,
    ]);
});

it('reports no reason for a person that no exclusion touches', function (): void {
    ($this->allFourApply)();

    $reasons = ($this->resolver)()->reasonsFor(
        ModelStub::ulid('stranger'),
        ($this->record)($this->ownerId),
        ApprovalExclusionSet::strict(),
        $this->triggerId,
        ['amount'],
    );

    expect($reasons)->toBe([]);
});

it('blocks a delegate when either the delegate or the represented person is excluded', function (): void {
    ($this->allFourApply)();

    $resolver = ($this->resolver)();
    $record = ($this->record)($this->ownerId);
    $stranger = ModelStub::ulid('stranger');
    $other = ModelStub::ulid('other-stranger');

    expect($resolver->isDelegateBlocked($this->ownerId, $stranger, $record, ApprovalExclusionSet::strict(), $this->triggerId, ['amount']))->toBeTrue()
        ->and($resolver->isDelegateBlocked($stranger, $this->ownerId, $record, ApprovalExclusionSet::strict(), $this->triggerId, ['amount']))->toBeTrue()
        ->and($resolver->isDelegateBlocked($stranger, $other, $record, ApprovalExclusionSet::strict(), $this->triggerId, ['amount']))->toBeFalse();
});

it('never puts an empty identifier into the excluded set', function (): void {
    $this->editors->shouldReceive('lastEditorOfFields')->andReturn('');
    $this->editors->shouldReceive('creatorOf')->andReturn('');

    $excluded = ($this->resolver)()->excludedUserIds(
        ($this->record)(''),
        ApprovalExclusionSet::strict(),
        '',
        ['amount'],
    );

    expect($excluded)->toBe([]);
});

it('returns each excluded person once in a stable first seen order', function (): void {
    $shared = ModelStub::ulid('shared');

    $this->editors->shouldReceive('lastEditorOfFields')->andReturn($shared);
    $this->editors->shouldReceive('creatorOf')->andReturn($shared);

    $excluded = ($this->resolver)()->excludedUserIds(
        ($this->record)($shared),
        ApprovalExclusionSet::strict(),
        $this->triggerId,
        ['amount'],
    );

    expect($excluded)->toBe([$this->triggerId, $shared]);
});

it('applies only the trigger exclusion when there is no record at all', function (): void {
    $this->editors->shouldNotReceive('lastEditorOfFields');
    $this->editors->shouldNotReceive('creatorOf');

    $excluded = ($this->resolver)()->excludedUserIds(
        null,
        ApprovalExclusionSet::strict(),
        $this->triggerId,
        ['amount'],
    );

    expect($excluded)->toBe([$this->triggerId]);
});

it('resolves the same question only once and answers a repeat from its memo', function (): void {
    $this->editors->shouldReceive('lastEditorOfFields')->once()->andReturn($this->editorId);
    $this->editors->shouldReceive('creatorOf')->once()->andReturn($this->creatorId);

    $resolver = ($this->resolver)();
    $record = ($this->record)($this->ownerId);

    $first = $resolver->excludedUserIds($record, ApprovalExclusionSet::strict(), $this->triggerId, ['amount']);
    $second = $resolver->excludedUserIds($record, ApprovalExclusionSet::strict(), $this->triggerId, ['amount']);

    expect($second)->toBe($first);
});

it('treats a reordered and a duplicated field key list as the very same question', function (): void {
    $this->editors->shouldReceive('lastEditorOfFields')->once()->andReturn($this->editorId);
    $this->editors->shouldReceive('creatorOf')->once()->andReturn($this->creatorId);

    $resolver = ($this->resolver)();
    $record = ($this->record)($this->ownerId);

    $first = $resolver->excludedUserIds($record, ApprovalExclusionSet::strict(), $this->triggerId, ['amount', 'status']);
    $second = $resolver->excludedUserIds($record, ApprovalExclusionSet::strict(), $this->triggerId, ['status', 'amount', 'status', '']);

    expect($second)->toBe($first);
});

it('asks again once the record has been written to a new version', function (): void {
    $this->editors->shouldReceive('lastEditorOfFields')->twice()->andReturn($this->editorId, $this->creatorId);
    $this->editors->shouldReceive('creatorOf')->twice()->andReturn($this->creatorId);

    $resolver = ($this->resolver)();

    $first = $resolver->excludedUserIds(($this->record)($this->ownerId), ApprovalExclusionSet::strict(), $this->triggerId, ['amount']);

    $newer = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->ownerId,
        'version' => 4,
    ]);

    $second = $resolver->excludedUserIds($newer, ApprovalExclusionSet::strict(), $this->triggerId, ['amount']);

    expect($first)->toContain($this->editorId)
        ->and($second)->not->toContain($this->editorId);
});

it('sorts the field keys it hands to the editor resolver', function (): void {
    $this->editors->shouldReceive('lastEditorOfFields')
        ->once()
        ->with(Mockery::type(CustomRecord::class), ['amount', 'status'])
        ->andReturn($this->editorId);
    $this->editors->shouldReceive('creatorOf')->andReturn(null);

    ($this->resolver)()->excludedUserIds(
        ($this->record)(null),
        ApprovalExclusionSet::strict(),
        null,
        ['status', 'amount'],
    );
});
