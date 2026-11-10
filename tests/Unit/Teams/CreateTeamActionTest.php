<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeamAction;
use App\Models\Team;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant('acme');
    $this->actor = AccessContext::user($this->tenant, [], 'team-lead');
    $this->parentId = (string) ModelStub::ulid('foreign-parent');

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "teams"') && !str_contains($sql, 'exists')
            ? [['id' => $this->parentId, 'tenant_id' => $this->tenant->getKey(), 'name' => 'Fremd', 'slug' => 'fremd', 'descendant_team_ids' => '[]']]
            : [['exists' => false]],
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('refuses to create a team below a parent the actor may not adopt and writes nothing', function (): void {
    $gate = GateSpy::allowing();

    expect(fn () => app(CreateTeamAction::class)->execute($this->actor, ['name' => 'Vertrieb Nord', 'parent_team_id' => $this->parentId]))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe([
                'parent_team_id' => ['Das gewählte übergeordnete Team liegt außerhalb Ihres Verantwortungsbereichs.'],
            ]);
        })
        ->and($gate->wasAskedFor('adopt', ModelStub::make(Team::class, ['id' => $this->parentId])))->toBeTrue()
        ->and($this->connection->writtenStatements)->toBe([]);
});

it('creates a team below a parent the actor may adopt', function (): void {
    $gate = GateSpy::allowing('adopt');

    app(CreateTeamAction::class)->execute($this->actor, ['name' => 'Vertrieb Nord', 'parent_team_id' => $this->parentId]);

    expect($gate->wasAskedFor('adopt'))->toBeTrue()
        ->and($this->connection->writtenStatements)->not->toBe([]);
});
