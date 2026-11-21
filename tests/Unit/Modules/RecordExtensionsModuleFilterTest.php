<?php

declare(strict_types=1);

use App\Contracts\Modules\RecordPresentationExtensionInterface;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Modules\RecordExtensions;
use App\Support\Modules\RegisteredModuleComponents;
use Tests\Fixtures\Modules\nubos\example\src\ExampleRecordPresentation;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\Doubles\StaticModuleRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->coreExtension = new class implements RecordPresentationExtensionInterface
    {
        public function form(ObjectType $objectType, User $user, ?CustomRecord $record): array
        {
            return ['core' => 'form'];
        }

        public function index(ObjectType $objectType, User $user): array
        {
            return ['core' => 'index'];
        }

        public function resource(CustomRecord $record): array
        {
            return ['core' => 'resource'];
        }
    };

    $this->extensionsWhileRegistered = fn (array $registered): RecordExtensions => new RecordExtensions(
        new RegisteredModuleComponents(new FixtureModuleCatalog, new StaticModuleRegistry($registered)),
        presentation: [new ExampleRecordPresentation, $this->coreExtension],
    );
});

it('leaves out the record presentation of a module that is unregistered while its code is still installed', function (): void {
    $extensions = ($this->extensionsWhileRegistered)([]);

    expect($extensions->index(new ObjectType, new User))->toBe(['core' => 'index'])
        ->and($extensions->form(new ObjectType, new User))->toBe(['core' => 'form'])
        ->and($extensions->resource(new CustomRecord))->toBe(['core' => 'resource']);
});

it('includes the record presentation of a registered module', function (): void {
    $extensions = ($this->extensionsWhileRegistered)(['nubos/example']);

    expect($extensions->index(new ObjectType, new User))->toBe(['example' => 'index', 'core' => 'index']);
});
