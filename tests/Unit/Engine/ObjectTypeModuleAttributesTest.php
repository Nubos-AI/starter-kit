<?php

declare(strict_types=1);

use App\Actions\Engine\CreateObjectTypeAction;
use App\Actions\Engine\UpdateObjectTypeAction;
use App\Contracts\Engine\ObjectTypeBackingInterface;
use App\Http\Controllers\Engine\ObjectTypesController;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeBackingRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    config([
        'modules.object_types.attributes' => ['is_featured'],
        'modules.object_types.create_rules' => ['is_featured' => ['boolean']],
        'modules.object_types.update_rules' => ['is_featured' => ['sometimes', 'boolean']],
        'modules.models.'.ObjectType::class => ['fillable' => ['is_featured'], 'casts' => ['is_featured' => 'boolean']],
    ]);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('module-companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'name' => 'Companies',
        'is_system' => false,
    ]);

    $this->written = null;

    /** @var callable(Closure():mixed):array<string, list<string>> */
    $this->validationErrorsOf = function (Closure $callback): array {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    /** @var callable(array<string, mixed>):array<string, mixed>|null */
    $this->writtenBy = function (array $input): ?array {
        DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $callback): mixed => $callback());

        ObjectType::updating(function (ObjectType $objectType): bool {
            $this->written = $objectType->getDirty();

            return false;
        });

        app(UpdateObjectTypeAction::class)->execute($this->objectType, $input);

        return $this->written;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets a module make its object type attribute fillable and cast', function (): void {
    $objectType = new ObjectType(['is_featured' => '1']);

    expect($objectType->is_featured)->toBeTrue();
});

it('refuses an update whose module attribute breaks the rule the module registered', function (): void {
    $errors = ($this->validationErrorsOf)(fn (): ObjectType => app(UpdateObjectTypeAction::class)->execute($this->objectType, [
        'name' => 'Companies',
        'is_featured' => 'not-a-boolean',
    ]));

    expect($errors)->toHaveKey('is_featured');
});

it('writes a module attribute on update and drops an input the module did not declare', function (): void {
    $written = ($this->writtenBy)(['name' => 'Companies', 'is_featured' => true, 'is_undeclared' => true]);

    expect($written)->toHaveKey('is_featured', true)
        ->and($written)->not->toHaveKey('is_undeclared');
});

it('leaves the module attribute untouched when an update does not carry it', function (): void {
    $written = ($this->writtenBy)(['name' => 'Renamed']);

    expect($written)->toBe(['name' => 'Renamed']);
});

it('refuses a creation whose module attribute breaks the rule the module registered', function (): void {
    $errors = ($this->validationErrorsOf)(fn (): ObjectType => app(CreateObjectTypeAction::class)->execute([
        'key' => 'companies',
        'name' => 'Companies',
        'is_featured' => 'not-a-boolean',
    ]));

    expect($errors)->toHaveKey('is_featured');
});

it('hands the module attributes of an object type to the details form', function (): void {
    $backing = Mockery::mock(ObjectTypeBackingInterface::class);
    $backing->shouldReceive('hasRows')->andReturnFalse();
    $registry = Mockery::mock(ObjectTypeBackingRegistry::class);
    $registry->shouldReceive('for')->andReturn($backing);
    app()->instance(ObjectTypeBackingRegistry::class, $registry);

    $this->objectType->setRawAttributes([...$this->objectType->getAttributes(), 'is_featured' => true, 'nav_icon' => 'database', 'storage_strategy' => 'generic']);

    $request = Request::create('/', 'GET');
    $request->headers->set('X-Inertia', 'true');

    $props = app(ObjectTypesController::class)->edit($this->objectType)->toResponse($request)->getData(true)['props'];

    expect($props['objectType'])->toHaveKey('is_featured', true);
});
