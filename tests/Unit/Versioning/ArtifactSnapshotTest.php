<?php

declare(strict_types=1);

use App\Actions\Engine\SnapshotDefinitionAction;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Models\ObjectType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('object-type'),
        'key' => 'deal',
    ]);

    $this->refusedSnapshot = function (string $reason): void {
        $caught = null;

        $shape = QueryShape::attemptedBy(function () use (&$caught): void {
            try {
                app(SnapshotDefinitionAction::class)->executeForArtifacts(
                    [['kind' => ArtifactKind::ObjectTypes, 'model' => $this->objectType]],
                    (string) Str::ulid(),
                );
            } catch (MissingActingUserException $exception) {
                $caught = $exception;
            }
        });

        expect($caught)->toBeInstanceOf(MissingActingUserException::class)
            ->and($caught?->reason)->toBe($reason)
            ->and($shape)->toBeNull();
    };
});

afterEach(function (): void {
    Auth::logout();
    AccessContext::forgetTenant();
});

it('refuses an artifact snapshot while nobody is authenticated and leaves the database alone', function (): void {
    AccessContext::tenant();

    ($this->refusedSnapshot)('acting-user-not-authenticated');
});

it('refuses an artifact snapshot outside a bound tenant and leaves the database alone', function (): void {
    AccessContext::actAs(AccessContext::user(AccessContext::tenant()));
    AccessContext::forgetTenant();

    ($this->refusedSnapshot)('target-tenant-not-bound');
});
