<?php

declare(strict_types=1);

use App\Actions\Activities\DeleteRecordActivityAction;
use App\Handlers\Timeline\ActivityTimelineSource;
use App\Http\Controllers\Engine\RecordActivitiesController;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordActivity;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\Links\SourceLinkCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('activity-links-tenant');
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'activity-links-user'));
    AccessContext::suspendRowAccess();
    $this->activityId = ModelStub::ulid('activity-links-activity');
    $this->mailId = ModelStub::ulid('activity-links-mail');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('activity-links-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('activity-links-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->row = [
        'id' => $this->activityId,
        'tenant_id' => (string) $this->tenant->getKey(),
        'record_id' => $this->record->getKey(),
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
        'subject' => 'Angebot besprochen',
        'occurred_at' => '2026-10-01 09:00:00',
        'deleted_at' => null,
    ];

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'count(*) as "aggregate"') => [['aggregate' => 1]],
        str_contains($sql, 'from "record_activities"') => [$this->row],
        default => [],
    }, fn (): int => 1);

    $this->link = ['key' => 'email_message', 'id' => $this->mailId, 'label' => 'E-Mail: Angebot', 'href' => '/mails/1'];
    $this->catalog = Mockery::mock(SourceLinkCatalog::class);
    app()->instance(SourceLinkCatalog::class, $this->catalog);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Mockery::close();
    AccessContext::forgetTenant();
});

it('hands every activity of a record panel page its own pre-resolved quick links', function (): void {
    GateSpy::allowing('view', 'update');
    $this->catalog->shouldReceive('linksFor')
        ->once()
        ->withArgs(fn (User $user, Collection $items): bool => $user === $this->user && $items->pluck('id')->all() === [$this->activityId])
        ->andReturn([$this->activityId => [$this->link]]);

    $request = Request::create('/activities');
    $request->setUserResolver(fn (): User => $this->user);

    $payload = app(RecordActivitiesController::class)
        ->index($request, $this->record, $this->catalog)
        ->toResponse($request)
        ->getData(true);

    expect($payload['data'][0]['links'])->toBe([$this->link])
        ->and($payload['data'][0]['sourceType'])->toBe('email_message');
});

it('shows the quick links of an activity on the record timeline as well', function (): void {
    GateSpy::allowing('view');
    $this->catalog->shouldReceive('linksFor')->once()->andReturn([$this->activityId => [$this->link]]);

    $entry = ModelStub::make(TimelineEntry::class, [
        'id' => ModelStub::ulid('activity-links-entry'),
        'record_id' => $this->record->getKey(),
        'source_key' => 'activity',
        'source_id' => $this->activityId,
    ]);

    $visible = app(ActivityTimelineSource::class)->filterVisible($this->user, $this->record, collect([$entry]));

    expect($visible->first()?->payload['links'])->toBe([$this->link]);
});

it('deletes a standalone activity without touching any timeline', function (): void {
    $activity = ModelStub::make(RecordActivity::class, [...$this->row, 'record_id' => null]);

    app(DeleteRecordActivityAction::class)->execute($activity);

    expect($this->connection->writtenSqlOf('timeline_entries'))->toBe([])
        ->and($this->connection->writtenSqlOf('record_activities'))->toHaveCount(1);
});

it('deletes the timeline entries of an activity on a record together with the activity and no other entry', function (): void {
    $activity = ModelStub::make(RecordActivity::class, [...$this->row, 'record_id' => (string) $this->record->getKey()]);

    app(DeleteRecordActivityAction::class)->execute($activity);

    $timelineWrites = array_values(array_filter(
        $this->connection->writtenStatements,
        static fn (array $statement): bool => str_contains($statement['sql'], '"timeline_entries"'),
    ));

    expect($timelineWrites)->toHaveCount(1)
        ->and($timelineWrites[0]['sql'])->toContain('"record_id" = ?')
        ->and($timelineWrites[0]['sql'])->toContain('"source_key" = ?')
        ->and($timelineWrites[0]['sql'])->toContain('"source_id" = ?')
        ->and($timelineWrites[0]['bindings'])->toContain((string) $this->record->getKey(), 'activity', $this->activityId)
        ->and($this->connection->writtenSqlOf('record_activities'))->toHaveCount(1);
});
