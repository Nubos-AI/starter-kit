<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Models\CustomRecord;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\DefaultValueResolver;
use App\Support\Engine\RecordNumberFormatter;
use App\Support\Engine\RecordSearchText;
use App\Support\Engine\RecordValidator;
use App\Support\Engine\RollupRecomputer;
use App\Support\Modules\RecordExtensions;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('deals');

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "object_types"') => [[
                'id' => $this->objectTypeId,
                'tenant_id' => (string) $this->tenant->getKey(),
                'slug' => 'deals',
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => ModelStub::ulid('deal-record'),
                'tenant_id' => (string) $this->tenant->getKey(),
                'object_type_id' => $this->objectTypeId,
                'version' => 1,
                'data' => '{"amount":100,"items_count":0}',
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId]]);

    $recordNumbers = Mockery::mock(RecordNumberFormatter::class);
    $recordNumbers->shouldReceive('next')->andReturn('DEAL-0000000000');

    $extensions = Mockery::mock(RecordExtensions::class)->shouldIgnoreMissing();
    $extensions->shouldReceive('attributes')->andReturn([]);
    $extensions->shouldReceive('auditAttributes')->andReturn([]);

    $this->steps = [];
    $this->rollups = Mockery::mock(RollupRecomputer::class);
    $this->formulas = Mockery::mock(ComputedFieldWriter::class);

    $this->action = new CreateRecordAction(
        $recordNumbers,
        Mockery::mock(RecordValidator::class)->shouldIgnoreMissing(),
        app(DefaultValueResolver::class),
        Mockery::mock(AuditRecorder::class)->shouldIgnoreMissing(),
        $this->formulas,
        $extensions,
        $this->rollups,
        Mockery::mock(RecordSearchText::class)->shouldIgnoreMissing(),
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('materialises the own roll-ups of a new record before its formulas read them', function (): void {
    $this->rollups->shouldReceive('materializeOwn')->once()->andReturnUsing(function (CustomRecord $record): array {
        $this->steps[] = 'rollups';
        $record->data = [...($record->data ?? []), 'items_count' => 0];
        $record->syncOriginalAttribute('data');

        return ['items_count'];
    });
    $this->formulas->shouldReceive('materialize')->once()->andReturnUsing(function (CustomRecord $record): array {
        $this->steps[] = 'formulas:'.($record->data['items_count'] ?? 'missing');

        return ['value'];
    });

    $this->action->execute(['object_type_id' => $this->objectTypeId, 'data' => ['amount' => 100]]);

    expect($this->steps)->toBe(['rollups', 'formulas:0']);
});

it('hands the formulas the record as written when no roll-up of the new record changed', function (): void {
    $this->rollups->shouldReceive('materializeOwn')->once()->andReturn([]);
    $this->formulas->shouldReceive('materialize')->once()->andReturnUsing(function (CustomRecord $record): array {
        $this->steps[] = $record->data;

        return [];
    });

    $this->action->execute(['object_type_id' => $this->objectTypeId, 'data' => ['amount' => 100]]);

    expect($this->steps)->toBe([['amount' => 100]]);
});

it('takes the materialised roll-ups over from their statement instead of reloading the new record', function (): void {
    $this->rollups->shouldReceive('materializeOwn')->once()->andReturnUsing(function (CustomRecord $record): array {
        $record->data = [...($record->data ?? []), 'items_count' => 0];
        $record->syncOriginalAttribute('data');

        return ['items_count'];
    });
    $this->formulas->shouldReceive('materialize')->once()->andReturn([]);

    $record = $this->action->execute(['object_type_id' => $this->objectTypeId, 'data' => ['amount' => 100]]);

    $reloads = array_filter(
        $this->connection->sqlOf('custom_records'),
        static fn (string $sql): bool => str_contains($sql, '"id" = ?'),
    );

    expect($reloads)->toBe([])
        ->and($record->data)->toBe(['amount' => 100, 'items_count' => 0]);
});
