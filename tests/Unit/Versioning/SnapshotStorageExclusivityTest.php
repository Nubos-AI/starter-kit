<?php

declare(strict_types=1);

use App\Actions\Engine\SnapshotDefinitionAction;
use App\Models\ConfigurationArtifactVersion;
use App\Models\FieldDefinitionVersion;
use App\Models\ObjectTypeDefinitionVersion;
use Tests\Support\InstalledArtifactKinds;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tokens = static fn (): array => [
        'object_type_versions' => ObjectTypeDefinitionVersion::class,
        'field_definition_versions' => FieldDefinitionVersion::class,
        'configuration_artifact_versions' => ConfigurationArtifactVersion::class,
    ];

    $this->storage = static function (): array {
        $storage = config('engine.definition_snapshot.storage');

        expect($storage)->toBeArray();

        /** @var array<string, mixed> $storage */
        return $storage;
    };

    $this->createdModelsOf = static function (string $class): array {
        $file = (new ReflectionClass($class))->getFileName();

        expect($file)->toBeString();

        $source = (string) file_get_contents((string) $file);

        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)::query\(\)->create\(/', $source, $matches);

        return array_values(array_unique($matches[1]));
    };
});

it('declares exactly one storage target for every artifact kind and never automation versions', function (): void {
    $storage = ($this->storage)();
    $missing = [];
    $invalid = [];

    foreach (InstalledArtifactKinds::all() as $kind) {
        if (!array_key_exists($kind->value, $storage)) {
            $missing[] = $kind->value;

            continue;
        }

        $token = $storage[$kind->value];

        if (!array_key_exists(is_string($token) ? $token : '', ($this->tokens)())) {
            $invalid[] = $kind->value.' => '.(is_string($token) ? $token : gettype($token));
        }
    }

    expect($missing)->toBe([])
        ->and($invalid)->toBe([])
        ->and(array_values($storage))->not->toContain('automation_versions')
        ->and(count($storage))->toBe(count(InstalledArtifactKinds::all()));
});

it('backs every declared storage token with a version model of that table', function (): void {
    foreach (($this->tokens)() as $token => $model) {
        $table = (new $model)->getTable();

        expect($table)->toBe($token === 'object_type_versions' ? 'object_type_definition_versions' : $token);
    }
});

it('lets the snapshot action create rows through the declared version models only', function (): void {
    expect(($this->createdModelsOf)(SnapshotDefinitionAction::class))->toBe([
        'ObjectTypeDefinitionVersion',
        'FieldDefinitionVersion',
        'ConfigurationArtifactVersion',
    ]);
});
