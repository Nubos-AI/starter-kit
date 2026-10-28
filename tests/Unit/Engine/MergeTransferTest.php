<?php

declare(strict_types=1);

use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeTransferCategory;
use App\Models\CustomRecord;
use App\Models\MergeRule;
use App\Support\Engine\MergeTransferCounter;
use App\Support\Engine\MergeTransferExecutor;
use App\Support\Engine\RollupOwnerStarter;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->targetId = ModelStub::ulid('merge-target');
    $this->sourceId = ModelStub::ulid('merge-source');
    $this->otherId = ModelStub::ulid('merge-other');

    $this->target = ModelStub::make(CustomRecord::class, [
        'id' => $this->targetId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
    ]);

    $this->source = ModelStub::make(CustomRecord::class, [
        'id' => $this->sourceId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('companies'),
    ]);

    $this->rollupStarter = Mockery::mock(RollupOwnerStarter::class);
    $this->rollupStarter->shouldReceive('startForRecordIds')->byDefault();

    $this->executor = new MergeTransferExecutor(new MergeTransferCounter, $this->rollupStarter);

    /** @var callable(array<string, string>):MergeRuleDecision */
    $this->decisionMoving = fn (array $policies): MergeRuleDecision => new MergeRuleDecision(
        MergeRuleMode::Allow,
        ModelStub::make(MergeRule::class, [
            'id' => ModelStub::ulid('merge-rule'),
            'tenant_id' => $this->tenantId,
            'transfer_policy' => $policies,
        ]),
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('folds a watcher the target already has and moves the one it does not', function (): void {
    $takenId = ModelStub::ulid('watcher-taken');
    $freeId = ModelStub::ulid('watcher-free');
    $takenUserId = ModelStub::ulid('watcher-user-taken');

    StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'exists') => [['exists' => in_array($takenUserId, $bindings, true)]],
            str_contains($sql, 'from "record_watchers"') => [
                ['id' => $takenId, 'tenant_id' => $this->tenantId, 'record_id' => $this->sourceId, 'user_id' => $takenUserId],
                ['id' => $freeId, 'tenant_id' => $this->tenantId, 'record_id' => $this->sourceId, 'user_id' => ModelStub::ulid('watcher-user-free')],
            ],
            default => [],
        },
        static fn (): int => 1,
    );

    $report = $this->executor->execute(
        $this->target,
        $this->source,
        ($this->decisionMoving)([MergeTransferCategory::Watchers->value => 'move']),
    );

    $watchers = $report[MergeTransferCategory::Watchers->value];

    expect($watchers['folded'])->toBe([$takenId])
        ->and($watchers['moved'])->toHaveCount(1)
        ->and($watchers['moved'][0]['id'])->toBe($freeId)
        ->and($watchers['moved'][0]['before'])->toBe(['record_id' => $this->sourceId]);
});

it('folds a link that would point the record at itself and keeps the rest of the edge intact', function (): void {
    $selfLinkId = ModelStub::ulid('link-self');

    $connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'exists') => [['exists' => false]],
            str_contains($sql, 'from "record_links"') => [[
                'id' => $selfLinkId,
                'tenant_id' => $this->tenantId,
                'relationship_type_id' => ModelStub::ulid('rel'),
                'from_record_id' => $this->targetId,
                'to_record_id' => $this->sourceId,
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    $report = $this->executor->execute(
        $this->target,
        $this->source,
        ($this->decisionMoving)([MergeTransferCategory::Links->value => 'move']),
    );

    expect($report[MergeTransferCategory::Links->value]['folded'])->toBe([$selfLinkId])
        ->and($report[MergeTransferCategory::Links->value]['moved'])->toBe([])
        ->and($connection->writtenSqlOf('record_links'))->toHaveCount(1);
});

it('folds a link whose rewritten edge already exists on the target', function (): void {
    $duplicateId = ModelStub::ulid('link-duplicate');

    StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'exists') => [['exists' => true]],
            str_contains($sql, 'from "record_links"') => [[
                'id' => $duplicateId,
                'tenant_id' => $this->tenantId,
                'relationship_type_id' => ModelStub::ulid('rel'),
                'from_record_id' => $this->sourceId,
                'to_record_id' => $this->otherId,
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    $report = $this->executor->execute(
        $this->target,
        $this->source,
        ($this->decisionMoving)([MergeTransferCategory::Links->value => 'move']),
    );

    expect($report[MergeTransferCategory::Links->value]['folded'])->toBe([$duplicateId])
        ->and($report[MergeTransferCategory::Links->value]['moved'])->toBe([]);
});

it('moves a link the target does not carry yet and remembers both roll-up owners', function (): void {
    $linkId = ModelStub::ulid('link-moved');

    StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'exists') => [['exists' => false]],
            str_contains($sql, 'from "record_links"') => [[
                'id' => $linkId,
                'tenant_id' => $this->tenantId,
                'relationship_type_id' => ModelStub::ulid('rel'),
                'from_record_id' => $this->sourceId,
                'to_record_id' => $this->otherId,
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    $owners = [];
    $this->rollupStarter->shouldReceive('startForRecordIds')
        ->andReturnUsing(function (string $tenantId, array $recordIds) use (&$owners): void {
            $owners = [$tenantId, $recordIds];
        });

    $report = $this->executor->execute(
        $this->target,
        $this->source,
        ($this->decisionMoving)([MergeTransferCategory::Links->value => 'move']),
    );

    expect($report[MergeTransferCategory::Links->value]['moved'])->toHaveCount(1)
        ->and($report[MergeTransferCategory::Links->value]['moved'][0]['before'])
        ->toBe(['from_record_id' => $this->sourceId, 'to_record_id' => $this->otherId])
        ->and($report[MergeTransferCategory::Links->value]['folded'])->toBe([])
        ->and($owners[0])->toBe($this->tenantId)
        ->and($owners[1])->toBe([$this->sourceId, $this->targetId]);
});

it('reads nothing and reports nothing for a category the rule tells it to keep', function (): void {
    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 0,
    );

    $report = $this->executor->execute(
        $this->target,
        $this->source,
        ($this->decisionMoving)([MergeTransferCategory::Notes->value => 'keep']),
    );

    expect($report[MergeTransferCategory::Notes->value])
        ->toBe(['policy' => 'keep', 'moved' => [], 'folded' => [], 'discarded' => []])
        ->and($connection->sqlOf('record_notes'))->toBe([]);
});
