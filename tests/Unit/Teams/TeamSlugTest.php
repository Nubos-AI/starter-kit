<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeamAction;
use App\Actions\Teams\UpdateTeamAction;
use App\Models\Team;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);
    $this->ulidSlug = strtolower((string) ModelStub::ulid('other-team'));
    $this->message = 'Die Kennung darf nicht wie ein interner Team-Schlüssel aufgebaut sein. Bitte wählen Sie eine andere Kennung.';
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to create a team whose slug has the shape of a team key before anything is asked', function (): void {
    $attempt = null;

    $shape = QueryShape::attemptedBy(function () use (&$attempt): void {
        try {
            app(CreateTeamAction::class)->execute($this->actor, ['name' => 'Vertrieb', 'slug' => $this->ulidSlug]);
        } catch (ValidationException $exception) {
            $attempt = $exception;
        }
    });

    expect($shape)->toBeNull()
        ->and($attempt?->errors()['slug'] ?? null)->toBe([$this->message]);
});

it('refuses to rename the slug of a team to the shape of a team key before anything is asked', function (): void {
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Vertrieb', 'slug' => 'vertrieb']);
    $attempt = null;

    $shape = QueryShape::attemptedBy(function () use ($team, &$attempt): void {
        try {
            app(UpdateTeamAction::class)->execute($this->actor, $team, ['slug' => $this->ulidSlug]);
        } catch (ValidationException $exception) {
            $attempt = $exception;
        }
    });

    expect($shape)->toBeNull()
        ->and($attempt?->errors()['slug'] ?? null)->toBe([$this->message]);
});

it('never derives a slug in the shape of a team key from the name of a new team', function (): void {
    $shape = QueryShape::attemptedBy(fn () => app(CreateTeamAction::class)->execute($this->actor, ['name' => $this->ulidSlug]));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding("team-{$this->ulidSlug}"))->toBeTrue()
        ->and($shape->hasBinding($this->ulidSlug))->toBeFalse();
});
