<?php

declare(strict_types=1);

use App\Enums\Export\ExportJobStatus;
use App\Models\User;
use App\Notifications\ExportCompletedNotification;
use App\Support\Export\ExportFinalizer;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->tenantId = ModelStub::ulid('export-finalizer-tenant');
    $this->userId = ModelStub::ulid('export-finalizer-user');
    $this->exportJobId = ModelStub::ulid('export-finalizer-job');

    $this->tenantRow = ['id' => $this->tenantId, 'name' => 'Nubos'];
    $this->userRow = ['id' => $this->userId, 'tenant_id' => $this->tenantId, 'email' => 'exporter@example.test'];

    /** @var callable(?string, bool):StaticQueryConnection */
    $this->connectionWith = fn (?string $resultPath, bool $hasTenant = true, bool $hasJob = true): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => $hasTenant ? [$this->tenantRow] : [],
            str_contains($sql, 'from "users"') => [$this->userRow],
            str_contains($sql, 'from "export_jobs"') => $hasJob ? [[
                'id' => $this->exportJobId,
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
                'status' => ExportJobStatus::Running->value,
                'result_path' => $resultPath,
            ]] : [],
            default => [],
        },
        static fn (): int => 1,
    );

    $this->finalizer = new ExportFinalizer(new TenantBinder);

    /** @var callable():void */
    $this->finalize = fn (): mixed => $this->finalizer->finalize($this->tenantId, $this->userId, $this->exportJobId);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    app()->forgetInstance('current_tenant');
});

it('marks the export as completed and tells the requesting user once a result file exists', function (): void {
    $connection = ($this->connectionWith)('exports/report.csv');

    ($this->finalize)();

    expect($connection->writtenSqlOf('export_jobs'))->toHaveCount(1)
        ->and($connection->writtenStatements[0]['bindings'])->toContain(ExportJobStatus::Completed->value);

    Notification::assertCount(1);
    Notification::assertSentTo(
        ModelStub::make(User::class, $this->userRow),
        ExportCompletedNotification::class,
    );
});

it('marks an export without a result file as failed and stays silent', function (): void {
    $connection = ($this->connectionWith)(null);

    ($this->finalize)();

    expect($connection->writtenSqlOf('export_jobs'))->toHaveCount(1)
        ->and($connection->writtenStatements[0]['bindings'])->toContain(ExportJobStatus::Failed->value)
        ->and($connection->writtenStatements[0]['bindings'])->not->toContain(ExportJobStatus::Completed->value);

    Notification::assertNothingSent();
});

it('never reads the export job for a tenant that no longer exists', function (): void {
    $connection = ($this->connectionWith)('exports/report.csv', false);

    ($this->finalize)();

    expect($connection->sqlOf('export_jobs'))->toBe([])
        ->and($connection->writtenStatements)->toBe([]);

    Notification::assertNothingSent();
});

it('writes nothing when the export job vanished before the finalisation', function (): void {
    $connection = ($this->connectionWith)('exports/report.csv', true, false);

    ($this->finalize)();

    expect($connection->writtenStatements)->toBe([]);

    Notification::assertNothingSent();
});

it('releases the tenant it bound for the finalisation', function (): void {
    ($this->connectionWith)('exports/report.csv');

    ($this->finalize)();

    expect(app()->bound('current_tenant'))->toBeFalse();
});

it('reads the export job only inside the tenant it finalises for and only by the job it was handed', function (): void {
    $connection = ($this->connectionWith)('exports/report.csv');

    ($this->finalize)();

    $jobQueries = array_values(array_filter(
        $connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'from "export_jobs"'),
    ));

    expect($jobQueries)->toHaveCount(1);

    $shape = new QueryShape($jobQueries[0]['sql'], $jobQueries[0]['bindings']);

    expect($shape->isScopedToTenant('export_jobs', $this->tenantId))->toBeTrue()
        ->and($shape->isKeyedTo('export_jobs', $this->exportJobId))->toBeTrue();
});

it('releases the hidden tenant context it set for the finalisation and restores the one bound before', function (): void {
    ($this->connectionWith)('exports/report.csv');
    Context::addHidden('tenant_id', 'previous-tenant');

    ($this->finalize)();

    expect(Context::getHidden('tenant_id'))->toBe('previous-tenant');

    Context::forgetHidden('tenant_id');
    ($this->finalize)();

    expect(Context::hasHidden('tenant_id'))->toBeFalse();
});
