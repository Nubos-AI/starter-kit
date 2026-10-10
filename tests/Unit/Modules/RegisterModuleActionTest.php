<?php

declare(strict_types=1);

use App\Actions\Modules\RegisterModuleAction;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_starts_with($sql, 'select')
            ? [['id' => '01J00000000000000000000000', 'name' => 'nubos/example', 'disabled' => true, 'version' => null, 'installed_at' => null]]
            : [],
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('enables a module again that an earlier uninstall left disabled', function (): void {
    (new RegisterModuleAction(new FixtureModuleCatalog))->execute('nubos/example');

    $update = collect($this->connection->writtenStatements)->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "modules"'));

    expect($update)->not->toBeNull()
        ->and($update['sql'])->toContain('"disabled" = ?')
        ->and($update['bindings'])->toContain(false);
});
