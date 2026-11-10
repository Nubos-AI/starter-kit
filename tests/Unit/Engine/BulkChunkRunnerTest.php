<?php

declare(strict_types=1);

use App\Actions\Engine\DeleteRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Engine\BulkBatchReport;
use App\Support\Engine\BulkChunkRunner;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->actor = AccessContext::user($this->tenant, [], 'bulk-runner-actor');
    $this->objectTypeId = ModelStub::ulid('bulk-runner-object-type');
    $this->recordId = ModelStub::ulid('bulk-runner-record');
    $this->batchId = 'c3e8b3d5-2c3f-4a4b-8d9e-1b2c3d4e5f60';

    AccessContext::suspendRowAccess();

    $context = Mockery::mock(ActingUserContext::class);
    $context->shouldReceive('run')->andReturnUsing(fn (string $tenantId, string $userId, Closure $callback): mixed => $callback($this->actor));
    app()->instance(ActingUserContext::class, $context);

    $this->updateRecord = Mockery::mock(UpdateRecordAction::class);
    app()->instance(UpdateRecordAction::class, $this->updateRecord);

    $this->deleteRecord = Mockery::mock(DeleteRecordAction::class);
    app()->instance(DeleteRecordAction::class, $this->deleteRecord);

    /** @var callable(bool):void */
    $this->installRecords = function (bool $isWithinRowAccess): void {
        StaticQueryConnection::install(fn (string $sql): array => match (true) {
            str_starts_with($sql, 'select exists') => [['exists' => $isWithinRowAccess]],
            str_contains($sql, 'from "object_types"') => [[
                'id' => $this->objectTypeId,
                'tenant_id' => $this->tenantId,
                'slug' => 'deal',
                'key' => 'deal',
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => $this->recordId,
                'tenant_id' => $this->tenantId,
                'object_type_id' => $this->objectTypeId,
                'version' => 1,
                'data' => json_encode(['salary' => 120000]),
            ]],
            default => [],
        });
    };

    ($this->installRecords)(true);

    /** @var callable(string, array<string, mixed>):void */
    $this->run = fn (string $action, array $payload = []): mixed => app(BulkChunkRunner::class)->run(
        $action,
        $this->tenantId,
        (string) $this->actor->getKey(),
        $this->objectTypeId,
        [$this->recordId],
        $payload,
        $this->batchId,
    );

    BulkBatchReport::initProgress($this->batchId, $this->tenantId, (string) $this->actor->getKey(), 1);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::suspendRowAccess();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('reports a failed record write without leaking the sql and its values and logs it by identifier', function (): void {
    Log::spy();
    $this->updateRecord->shouldReceive('execute')->once()->andThrow(new QueryException(
        'pgsql',
        'update "custom_records" set "data" = ? where "id" = ?',
        ['{"salary":120000}', $this->recordId],
        new PDOException('SQLSTATE[22P02]: Invalid text representation'),
    ));

    ($this->run)('set-field', ['salary' => 'abc']);

    $errors = BulkBatchReport::errors($this->batchId);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['recordId'])->toBe($this->recordId)
        ->and($errors[0]['message'])->not->toContain('custom_records')
        ->and($errors[0]['message'])->not->toContain('120000')
        ->and($errors[0]['message'])->toContain('Bitte versuchen Sie es erneut');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['record_id'] === $this->recordId
            && $context['batch_id'] === $this->batchId
            && $context['action'] === 'set-field'
            && $context['exception'] === QueryException::class
            && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), '120000'));
});

it('reports the validation message of a refused record write to the user', function (): void {
    $this->updateRecord->shouldReceive('execute')->once()->andThrow(
        ValidationException::withMessages(['data.salary' => 'Gehalt muss eine Zahl sein.']),
    );

    ($this->run)('set-field', ['salary' => 'abc']);

    expect(BulkBatchReport::errors($this->batchId)[0]['message'])->toBe('Gehalt muss eine Zahl sein.');
});

it('reports a refused deletion as a refusal instead of an error', function (): void {
    AccessContext::grant('deal.delete');
    $this->deleteRecord->shouldReceive('execute')->once()->andThrow(new AuthorizationException('Löschgrund fehlt.'));

    ($this->run)('soft-delete');

    expect(BulkBatchReport::errors($this->batchId)[0]['message'])->toBe('Löschgrund fehlt.');
});

it('checks every record of a bulk deletion against the row access rules of the acting user', function (): void {
    AccessContext::grant('deal.delete');
    AccessContext::rowAccessRules([$this->objectTypeId => [[[
        'combinator' => 'and',
        'conditions' => [['field' => 'region', 'operator' => 'equals', 'value' => 'north']],
    ]]]]);
    $compiler = AccessContext::recordRuleCompiler();
    ($this->installRecords)(false);
    $this->deleteRecord->shouldNotReceive('execute');

    ($this->run)('soft-delete');

    expect(BulkBatchReport::errors($this->batchId)[0]['message'])->toBe('Sie dürfen diesen Datensatz nicht löschen.')
        ->and($compiler->calls)->not->toBeEmpty()
        ->and(app(RowAccessEnforcement::class)->isEnabled())->toBeFalse();
});
