<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Contracts\Modules\RecordCreationExtensionInterface;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\DefaultValueResolver;
use App\Support\Engine\RecordNumberFormatter;
use App\Support\Engine\RecordSearchText;
use App\Support\Engine\RecordValidator;
use App\Support\Engine\RollupRecomputer;
use App\Support\Modules\RecordExtensions;
use Illuminate\Validation\ValidationException;
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
            default => [],
        },
        static fn (): int => 1,
    );

    FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId]]);

    $recordNumbers = Mockery::mock(RecordNumberFormatter::class);
    $recordNumbers->shouldReceive('next')->andReturn('DEAL-0000000000');

    $this->steps = [];

    $this->extensions = Mockery::mock(RecordExtensions::class);
    $this->extensions->shouldReceive('attributes')->andReturn([]);
    $this->extensions->shouldReceive('auditAttributes')->andReturn([]);
    $this->extensions->shouldReceive('saved')->andReturnUsing(function (): void {
        $this->steps[] = 'saved';
    });

    $auditRecorder = Mockery::mock(AuditRecorder::class);
    $auditRecorder->shouldReceive('record')->andReturnUsing(function (): void {
        $this->steps[] = 'audit';
    });

    $rollups = Mockery::mock(RollupRecomputer::class);
    $rollups->shouldReceive('materializeOwn')->andReturnUsing(function (): array {
        $this->steps[] = 'rollups';

        return [];
    });

    $this->recordValidator = Mockery::mock(RecordValidator::class)->shouldIgnoreMissing();

    $this->action = new CreateRecordAction(
        $recordNumbers,
        $this->recordValidator,
        app(DefaultValueResolver::class),
        $auditRecorder,
        Mockery::mock(ComputedFieldWriter::class)->shouldIgnoreMissing(),
        $this->extensions,
        $rollups,
        Mockery::mock(RecordSearchText::class)->shouldIgnoreMissing(),
    );

    $this->input = [
        'object_type_id' => $this->objectTypeId,
        'data' => ['amount' => 100],
        'source_message_id' => ModelStub::ulid('source-message'),
    ];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('hands the created hook the saved record and the raw input right after the saved hook', function (): void {
    $this->extensions->shouldReceive('created')->once()->andReturnUsing(function (CustomRecord $record, array $input): void {
        $this->steps[] = 'created';

        expect($record->exists)->toBeTrue()
            ->and($record->data)->toBe(['amount' => 100])
            ->and($input)->toBe($this->input);
    });

    $this->action->execute($this->input);

    expect($this->steps)->toBe(['saved', 'created', 'audit', 'rollups']);
});

it('checks the unique fields again under lock inside the transaction before the record is inserted', function (): void {
    $this->extensions->shouldReceive('created');

    $this->recordValidator->shouldReceive('assertUniqueUnderLock')
        ->once()
        ->andReturnUsing(function (ObjectType $type, array $data, string $tenantId): void {
            expect($this->connection->transactionLevel())->toBe(1)
                ->and($this->connection->writtenStatements)->toBe([])
                ->and($data)->toBe(['amount' => 100])
                ->and($tenantId)->toBe((string) $this->tenant->getKey());
        });

    $this->action->execute($this->input);
});

it('runs the created hook for a record written without protocol as well', function (): void {
    $this->extensions->shouldReceive('created')->once()->andReturnUsing(function (CustomRecord $record, array $input): void {
        $this->steps[] = 'created';

        expect($input)->toBe($this->input);
    });

    $this->action->executeWithoutProtocol($this->input);

    expect($this->steps)->toBe(['saved', 'created', 'rollups']);
});

it('lets a failing created hook abort the creation before anything after it runs', function (): void {
    $this->extensions->shouldReceive('created')->once()->andThrow(new RuntimeException('link failed'));

    expect(fn (): CustomRecord => $this->action->execute($this->input))->toThrow(RuntimeException::class, 'link failed')
        ->and($this->steps)->toBe(['saved']);
});

it('fans the created hook out to every creation extension', function (): void {
    $record = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('deal-record')]);
    $input = ['source_message_id' => ModelStub::ulid('source-message')];

    $first = Mockery::mock(RecordCreationExtensionInterface::class);
    $first->shouldReceive('created')->once()->with($record, $input);
    $second = Mockery::mock(RecordCreationExtensionInterface::class);
    $second->shouldReceive('created')->once()->with($record, $input);

    (new RecordExtensions([$first, $second]))->created($record, $input);
});

it('refuses an object type that exists only in another tenant before any hook runs', function (): void {
    $verifier = FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId]])
        ->ownedByTenant(ModelStub::ulid('foreign-object-type-tenant'));

    $this->extensions->shouldNotReceive('created');

    expect(fn (): CustomRecord => $this->action->execute($this->input))->toThrow(ValidationException::class)
        ->and($verifier->wasLookedUpWithin('object_types', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($this->steps)->toBe([]);
});
