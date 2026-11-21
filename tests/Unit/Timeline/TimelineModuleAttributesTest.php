<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Http\Resources\Timeline\TimelineEntryResource;
use App\Models\CustomRecord;
use App\Models\TimelineEntry;
use App\Support\Timeline\ChangeTimelineWriter;
use App\Support\Timeline\TimelineRecorder;
use Illuminate\Http\Request;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('records a change made by a machine actor without naming any module of its own', function (): void {
    $recorded = [];
    $recorder = Mockery::mock(TimelineRecorder::class);
    $recorder->shouldReceive('record')->andReturnUsing(function (CustomRecord $record, string $sourceKey, array $entries) use (&$recorded): void {
        $recorded = $entries;
    });

    (new ChangeTimelineWriter($recorder))->record(ModelStub::make(CustomRecord::class), [[
        'source_id' => ModelStub::ulid('machine-change'),
        'occurred_at' => now(),
        'field_key' => 'headline',
        'old' => 'Alt',
        'new' => 'Neu',
        'actor_id' => ModelStub::ulid('machine-actor'),
        'actor_type' => ActorType::Automation->value,
    ]], []);

    expect($recorded[0]['payload'])->toBe(['field_key' => 'headline', 'old_value' => 'Alt', 'new_value' => 'Neu'])
        ->and($recorded[0]['actor_id'])->toBe(ModelStub::ulid('machine-actor'));
});

it('hands a timeline entry to the client with exactly the attributes modules registered', function (): void {
    $entry = ModelStub::make(TimelineEntry::class, [
        'id' => ModelStub::ulid('timeline-entry'),
        'source_key' => 'note',
        'occurred_at' => '2026-11-20 10:00:00',
    ]);
    $entry->setAttribute('can_escalate', true);

    config(['modules.timeline.entry_attributes' => []]);
    $without = TimelineEntryResource::make($entry)->toArray(Request::create('/'));

    config(['modules.timeline.entry_attributes' => [
        'canEscalate' => ['attribute' => 'can_escalate', 'default' => false],
        'canArchive' => ['attribute' => 'can_archive', 'default' => false],
    ]]);
    $with = TimelineEntryResource::make($entry)->toArray(Request::create('/'));

    expect($without)->not->toHaveKey('canEscalate')
        ->and($without)->not->toHaveKey('canOpenAutomation')
        ->and($with['canEscalate'])->toBeTrue()
        ->and($with['canArchive'])->toBeFalse();
});
