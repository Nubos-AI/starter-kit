<?php

declare(strict_types=1);

use App\Models\FieldDefinitionVersion;
use App\Models\ObjectTypeDefinitionVersion;
use Illuminate\Database\QueryException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    AccessContext::tenant();

    $this->migration = 'database/migrations/0001_01_01_000021_create_definition_version_tables.php';
    $this->objectTypeVersion = ModelStub::make(ObjectTypeDefinitionVersion::class, [
        'object_type_id' => ModelStub::ulid('versioned-object-type'),
        'version_number' => 1,
        'change_summary' => 'Initiale Definition',
    ]);
    $this->fieldVersion = ModelStub::make(FieldDefinitionVersion::class, [
        'object_type_id' => ModelStub::ulid('versioned-object-type'),
        'field_key' => 'amount',
        'version_number' => 1,
    ]);

    /** @var callable(Closure):RuntimeException */
    $this->refusalWithoutAQuery = function (Closure $mutation): RuntimeException {
        $thrown = null;

        $shape = QueryShape::attemptedBy(static function () use ($mutation, &$thrown): void {
            try {
                $mutation();
            } catch (RuntimeException $exception) {
                $thrown = $exception;
            }
        });

        expect($shape)->toBeNull();

        if (!$thrown instanceof RuntimeException) {
            $this->fail('the model let the mutation through');
        }

        expect($thrown)->not->toBeInstanceOf(QueryException::class);

        return $thrown;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to update or delete an object type definition version at the model', function (): void {
    expect(($this->refusalWithoutAQuery)(fn (): bool => $this->objectTypeVersion->update(['change_summary' => 'tampered'])))
        ->toBeInstanceOf(RuntimeException::class)
        ->and(($this->refusalWithoutAQuery)(fn (): ?bool => $this->objectTypeVersion->delete()))
        ->toBeInstanceOf(RuntimeException::class);
});

it('refuses to update or delete a field definition version at the model', function (): void {
    expect(($this->refusalWithoutAQuery)(fn (): bool => $this->fieldVersion->update(['field_key' => 'tampered'])))
        ->toBeInstanceOf(RuntimeException::class)
        ->and(($this->refusalWithoutAQuery)(fn (): ?bool => $this->fieldVersion->delete()))
        ->toBeInstanceOf(RuntimeException::class);
});

it('installs a row level trigger on both version tables for anything that bypasses the model', function (): void {
    $schema = SchemaShape::ofMigration($this->migration);

    expect($schema->has('RAISE EXCEPTION \'definition versions are append-only: % is not permitted\', TG_OP'))->toBeTrue()
        ->and($schema->has('CREATE TRIGGER trg_otdv_append_only'))->toBeTrue()
        ->and($schema->has('CREATE TRIGGER trg_fdv_append_only'))->toBeTrue()
        ->and($schema->has('BEFORE UPDATE OR DELETE ON object_type_definition_versions'))->toBeTrue()
        ->and($schema->has('BEFORE UPDATE OR DELETE ON field_definition_versions'))->toBeTrue();
});

it('keeps one version number per object type so a snapshot can never collide', function (): void {
    $schema = SchemaShape::ofMigration($this->migration);

    expect($schema->has('alter table "object_type_definition_versions" add constraint "unq_otdv_type_version" unique ("object_type_id", "version_number")'))->toBeTrue();
});

it('stores both snapshots as jsonb in the declared column order', function (): void {
    $schema = SchemaShape::ofMigration($this->migration);

    expect($schema->columnsOf('object_type_definition_versions'))->toBe([
        'id',
        'object_type_id',
        'snapshot_group_id',
        'version_number',
        'snapshot',
        'actor_id',
        'change_summary',
        'changed_at',
    ])
        ->and($schema->columnDefinition('object_type_definition_versions', 'snapshot'))->toStartWith('jsonb')
        ->and($schema->columnDefinition('field_definition_versions', 'snapshot'))->toStartWith('jsonb');
});

it('drops both triggers before the tables on the way down', function (): void {
    $down = SchemaShape::ofMigration($this->migration, 'down');

    expect($down->statements[0])->toContain('DROP TRIGGER IF EXISTS trg_fdv_append_only ON field_definition_versions')
        ->and($down->statements[1])->toContain('DROP TRIGGER IF EXISTS trg_otdv_append_only ON object_type_definition_versions')
        ->and($down->has('drop table if exists "field_definition_versions"'))->toBeTrue()
        ->and($down->has('drop table if exists "object_type_definition_versions"'))->toBeTrue();
});
