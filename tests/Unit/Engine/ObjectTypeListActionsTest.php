<?php

declare(strict_types=1);

use App\Actions\Engine\BulkDeleteObjectTypesAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->action = app(BulkDeleteObjectTypesAction::class);
    $this->actor = AccessContext::user($this->tenant);

    $this->regularId = ModelStub::ulid('regular-type');
    $this->systemId = ModelStub::ulid('system-type');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads the rows a bulk delete names inside the tenant only', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): Collection => $this->action->execute($this->actor, [
        'ids' => [$this->regularId, $this->systemId],
    ]));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('object_types'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hasBinding($this->regularId))->toBeTrue()
        ->and($attempt?->hasBinding($this->systemId))->toBeTrue()
        ->and($attempt?->hidesSoftDeleted('object_types'))->toBeTrue();
});

it('never widens a bulk delete beyond the identifiers it was given', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): Collection => $this->action->execute($this->actor, [
        'ids' => [$this->regularId],
    ]));

    expect($attempt?->isKeyedTo('object_types', $this->regularId))->toBeTrue()
        ->and($attempt?->hasBinding($this->systemId))->toBeFalse();
});

it('refuses an identifier list that is no list of identifiers', function (): void {
    expect(fn (): Collection => $this->action->execute($this->actor, ['ids' => ['nested' => ['x']]]))
        ->toThrow(ValidationException::class);
});
