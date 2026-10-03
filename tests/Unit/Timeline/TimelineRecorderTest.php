<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Enums\Timeline\TimelineChannel;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Handlers\Timeline\NoteTimelineSource;
use App\Models\CustomRecord;
use App\Support\Timeline\TimelineRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['timeline.sources' => ['note' => NoteTimelineSource::class]]);

    $this->tenant = AccessContext::tenant();

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('timeline-record'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->recorder = fn (): TimelineRecorder => app(TimelineRecorder::class);

    $this->onPath = static function (string $path): void {
        app()->instance('request', Request::create($path, 'POST'));
    };

    /**
     * @return list<array<string, mixed>>
     */
    $this->rowsOf = static function (QueryShape $shape): array {
        preg_match('/insert into "timeline_entries" \\(([^)]*)\\)/', $shape->sql, $matches);

        $columns = array_map(
            static fn (string $column): string => trim($column, ' "'),
            explode(',', $matches[1]),
        );

        return array_map(
            static fn (array $chunk): array => array_combine($columns, $chunk),
            array_chunk($shape->bindings, count($columns)),
        );
    };

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    $this->rowsFor = function (array $entries, ?CustomRecord $record = null): array {
        $shape = QueryShape::attemptedBy(fn () => ($this->recorder)()->record($record ?? $this->record, 'note', $entries));

        expect($shape)->not->toBeNull()
            ->and($shape->sql)->toStartWith('insert into "timeline_entries"');

        return ($this->rowsOf)($shape);
    };

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    $this->rowFor = function (array $entries, ?CustomRecord $record = null): array {
        return ($this->rowsFor)($entries, $record)[0];
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance('current_automation_actor');
    Date::setTestNow();
});

it('writes one projection row carrying source, record, tenant, moment and payload of the entry', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-03-04 10:11:12'));
    ($this->onPath)('/personal/engine/records/x');

    $row = ($this->rowFor)([[
        'source_id' => ModelStub::ulid('note-one'),
        'payload' => ['body' => 'Notiz'],
    ]]);

    expect($row['tenant_id'])->toBe((string) $this->tenant->getKey())
        ->and($row['record_id'])->toBe((string) $this->record->getKey())
        ->and($row['source_key'])->toBe('note')
        ->and($row['source_id'])->toBe(ModelStub::ulid('note-one'))
        ->and($row['payload'])->toBe('{"body":"Notiz"}')
        ->and((string) $row['occurred_at'])->toStartWith('2026-03-04 10:11:12');
});

it('generates a ulid key and both timestamps that a raw bulk insert would otherwise leave empty', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-03-04 10:11:12'));
    ($this->onPath)('/personal/engine/records/x');

    $row = ($this->rowFor)([['payload' => null]]);

    expect($row['id'])->toBeString()
        ->and(strlen((string) $row['id']))->toBe(26)
        ->and((string) $row['created_at'])->toStartWith('2026-03-04 10:11:12')
        ->and((string) $row['updated_at'])->toStartWith('2026-03-04 10:11:12');
});

it('takes the tenant from the record instead of the bound container tenant', function (): void {
    ($this->onPath)('/personal/engine/records/x');

    $foreignRecord = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('foreign-record'),
        'tenant_id' => ModelStub::ulid('foreign-tenant'),
    ]);

    $row = ($this->rowFor)([['payload' => null]], $foreignRecord);

    expect($row['tenant_id'])->toBe(ModelStub::ulid('foreign-tenant'))
        ->and($row['tenant_id'])->not->toBe((string) $this->tenant->getKey())
        ->and($row['record_id'])->toBe(ModelStub::ulid('foreign-record'));
});

it('writes three entries with a single insert statement and a key of its own per row', function (): void {
    ($this->onPath)('/personal/engine/records/x');

    $rows = ($this->rowsFor)([
        ['payload' => ['n' => 1]],
        ['payload' => ['n' => 2]],
        ['payload' => ['n' => 3]],
    ]);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'payload'))->toBe(['{"n":1}', '{"n":2}', '{"n":3}'])
        ->and(array_unique(array_column($rows, 'id')))->toHaveCount(3);
});

it('writes nothing and raises nothing for an empty entry list', function (): void {
    ($this->onPath)('/personal/engine/records/x');

    expect(QueryShape::attemptedBy(fn () => ($this->recorder)()->record($this->record, 'note', [])))->toBeNull();
});

it('rejects an unknown source key before it looks at the entry list at all', function (): void {
    expect(fn () => ($this->recorder)()->record($this->record, 'nope', [['payload' => ['body' => 'x']]]))
        ->toThrow(UnknownTimelineSourceException::class)
        ->and(fn () => ($this->recorder)()->record($this->record, 'nope', []))
        ->toThrow(UnknownTimelineSourceException::class);
});

it('stamps a change made in the browser as the web channel', function (): void {
    ($this->onPath)('/personal/engine/records/x');
    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $row = ($this->rowFor)([['payload' => null]]);

    expect($row['actor_id'])->toBe((string) $user->getKey())
        ->and($row['actor_type'])->toBe(ActorType::User->value)
        ->and($row['channel'])->toBe(TimelineChannel::Web->value);
});

it('stamps a change made through the api as the api channel', function (): void {
    ($this->onPath)('/api/v1/records/x');
    AccessContext::actAs(AccessContext::user($this->tenant));

    expect(($this->rowFor)([['payload' => null]])['channel'])->toBe(TimelineChannel::Api->value);
});

it('stamps a change made by an automation as the automation channel and prefers the marker over the user', function (): void {
    ($this->onPath)('/personal/engine/records/x');

    $user = AccessContext::actAs(AccessContext::user($this->tenant));
    $automation = ModelStub::ulid('automation-actor');

    app()->instance('current_automation_actor', ['id' => $automation, 'type' => ActorType::Automation]);

    $row = ($this->rowFor)([['payload' => null]]);

    expect($row['actor_id'])->toBe($automation)
        ->and($row['actor_id'])->not->toBe((string) $user->getKey())
        ->and($row['actor_type'])->toBe(ActorType::Automation->value)
        ->and($row['channel'])->toBe(TimelineChannel::Automation->value);
});

it('stamps a change without any actor as the system channel and leaves the actor empty', function (): void {
    ($this->onPath)('/personal/engine/records/x');

    $row = ($this->rowFor)([['payload' => null]]);

    expect($row['actor_id'])->toBeNull()
        ->and($row['actor_type'])->toBeNull()
        ->and($row['channel'])->toBe(TimelineChannel::System->value);
});

it('lets an entry carry an actor of its own and derives the channel from that actor', function (): void {
    ($this->onPath)('/personal/engine/records/x');
    AccessContext::actAs(AccessContext::user($this->tenant));

    $row = ($this->rowFor)([[
        'payload' => null,
        'actor_id' => ModelStub::ulid('own-automation'),
        'actor_type' => ActorType::Automation->value,
    ]]);

    expect($row['actor_id'])->toBe(ModelStub::ulid('own-automation'))
        ->and($row['channel'])->toBe(TimelineChannel::Automation->value);
});

it('stamps an entry that carries no actor of its own as the system channel although a user is signed in', function (): void {
    ($this->onPath)('/personal/engine/records/x');
    AccessContext::actAs(AccessContext::user($this->tenant));

    $row = ($this->rowFor)([[
        'payload' => null,
        'actor_id' => null,
        'actor_type' => null,
    ]]);

    expect($row['actor_id'])->toBeNull()
        ->and($row['channel'])->toBe(TimelineChannel::System->value);
});

it('falls back to the current moment when the entry carries no occurrence time', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-05-06 07:08:09'));
    ($this->onPath)('/personal/engine/records/x');

    expect((string) ($this->rowFor)([['payload' => null]])['occurred_at'])->toStartWith('2026-05-06 07:08:09');
});

it('keeps a past occurrence time exactly as it was handed in', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-05-06 07:08:09'));
    ($this->onPath)('/personal/engine/records/x');

    $row = ($this->rowFor)([[
        'payload' => null,
        'occurred_at' => CarbonImmutable::parse('2020-01-02 03:04:05'),
    ]]);

    expect((string) $row['occurred_at'])->toStartWith('2020-01-02 03:04:05');
});

it('round trips a nested payload and keeps an absent payload null', function (): void {
    ($this->onPath)('/personal/engine/records/x');

    $rows = ($this->rowsFor)([
        ['payload' => ['outer' => ['inner' => [1, 2], 'flag' => true]]],
        [],
    ]);

    expect($rows[0]['payload'])->toBe('{"outer":{"inner":[1,2],"flag":true}}')
        ->and($rows[1]['payload'])->toBeNull();
});
