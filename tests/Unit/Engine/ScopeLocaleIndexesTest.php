<?php

declare(strict_types=1);

use App\Actions\Engine\ScopeLocaleIndexesAction;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->action = app(ScopeLocaleIndexesAction::class);
    $this->typeId = ModelStub::ulid('locale-index-migration');
    $this->name = 'idx_custom_records_'.substr(md5($this->typeId), 0, 12).'_name_de_en';
    $this->definition = "CREATE INDEX {$this->name} ON public.custom_records USING btree (tenant_id, object_type_id, COALESCE(((data -> 'name'::text) ->> 'de'::text), ((data -> 'name'::text) ->> 'en'::text)), id)";
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('builds a scoped replacement before atomically swapping names and dropping the old index', function (): void {
    $connection = StaticQueryConnection::install(fn (): array => [[
        'indexname' => $this->name,
        'indexdef' => $this->definition.' WHERE (deleted_at IS NULL)',
        'object_type_id' => $this->typeId,
    ]], static fn (): int => 0);

    $this->action->execute();

    $replacement = 'idx_locale_new_'.md5($this->name);
    $previous = 'idx_locale_old_'.md5($this->name);
    $statements = array_column($connection->writtenStatements, 'sql');

    expect($statements[2])->toStartWith("CREATE INDEX CONCURRENTLY {$replacement}")
        ->and($statements[2])->toContain("WHERE object_type_id = '{$this->typeId}' AND deleted_at IS NULL")
        ->and($statements[3])->toBe("SET LOCAL lock_timeout = '5s'")
        ->and($statements[4])->toBe("ALTER INDEX {$this->name} RENAME TO {$previous}")
        ->and($statements[5])->toBe("ALTER INDEX {$replacement} RENAME TO {$this->name}")
        ->and($statements[6])->toBe("DROP INDEX CONCURRENTLY IF EXISTS {$previous}")
        ->and($connection->openedTransactions)->toBe(1);
});

it('leaves the live index untouched when building the replacement fails', function (): void {
    $connection = StaticQueryConnection::install(fn (): array => [[
        'indexname' => $this->name,
        'indexdef' => $this->definition.' WHERE (deleted_at IS NULL)',
        'object_type_id' => $this->typeId,
    ]], static function (string $sql): int {
        if (str_starts_with($sql, 'CREATE INDEX')) {
            throw new RuntimeException('Build interrupted');
        }

        return 0;
    });

    expect(fn () => $this->action->execute())->toThrow(RuntimeException::class, 'Build interrupted')
        ->and($connection->openedTransactions)->toBe(0)
        ->and(implode("\n", array_column($connection->writtenStatements, 'sql')))->not->toContain('ALTER INDEX');
});

it('cleans up an interrupted swap without rebuilding an already scoped live index', function (): void {
    $connection = StaticQueryConnection::install(fn (): array => [[
        'indexname' => $this->name,
        'indexdef' => $this->definition." WHERE ((object_type_id = '{$this->typeId}'::bpchar) AND (deleted_at IS NULL))",
        'object_type_id' => $this->typeId,
    ]], static fn (): int => 0);

    $this->action->execute();

    expect(array_column($connection->writtenStatements, 'sql'))->toBe([
        'DROP INDEX CONCURRENTLY IF EXISTS idx_locale_old_'.md5($this->name),
    ]);
});
