<?php

declare(strict_types=1);

use App\Actions\Engine\RecomputeFieldAction;
use App\Actions\Formulas\StartFormulaBackfillAction;
use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\BackfillStatus;
use App\Http\Controllers\Engine\FieldRecomputesController;
use App\Models\FieldDefinition;
use App\Models\FormulaBackfillRun;
use App\Models\ObjectType;
use App\Support\Engine\FieldOwnershipGuard;
use App\Support\Engine\RollupBackfillStarter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('recompute-object-type');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'invoices',
    ]);

    /** @var callable(FieldType, ?string):FieldDefinition */
    $this->field = fn (FieldType $type, ?string $objectTypeId = null): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('recompute-field-'.$type->value),
            'object_type_id' => $objectTypeId ?? $this->objectTypeId,
            'key' => 'brutto',
            'field_type' => $type,
            'config' => ['formula' => '{netto} * 1,19', 'result_type' => 'number'],
        ],
    );

    $this->formulaStarter = Mockery::spy(StartFormulaBackfillAction::class);
    $this->rollupStarter = Mockery::spy(RollupBackfillStarter::class);

    $this->action = new RecomputeFieldAction($this->formulaStarter, $this->rollupStarter);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

describe('RecomputeFieldAction decides what a field is recomputed by', function (): void {
    test('a formula field is recomputed by opening a formula backfill run', function (): void {
        $field = ($this->field)(FieldType::Computed);

        $run = ModelStub::make(FormulaBackfillRun::class, [
            'id' => ModelStub::ulid('recompute-run'),
            'tenant_id' => $this->tenant->getKey(),
            'field_definition_id' => $field->getKey(),
            'object_type_id' => $this->objectTypeId,
            'status' => BackfillStatus::Pending,
        ]);

        $this->formulaStarter->shouldReceive('execute')->once()->with($field)->andReturn($run);

        expect($this->action->execute($field))->toBe($run);

        $this->rollupStarter->shouldNotHaveReceived('backfill');
    });

    test('a roll-up field is recomputed by the roll-up starter and opens no formula run', function (): void {
        $field = ($this->field)(FieldType::Rollup);

        expect($this->action->execute($field))->toBeNull();

        $this->rollupStarter->shouldHaveReceived('backfill')->once()->with($field);
        $this->formulaStarter->shouldNotHaveReceived('execute');
    });

    test('a field that carries no calculation is rejected and nothing is started', function (FieldType $type): void {
        $field = ($this->field)($type);

        expect(fn (): ?FormulaBackfillRun => $this->action->execute($field))
            ->toThrow(ValidationException::class);

        $this->formulaStarter->shouldNotHaveReceived('execute');
        $this->rollupStarter->shouldNotHaveReceived('backfill');
    })->with([
        'a short text field' => [FieldType::TextShort],
        'a number field' => [FieldType::Number],
        'a date field' => [FieldType::Date],
    ]);

    test('the rejection names the field key it was handed', function (): void {
        $errors = null;

        try {
            $this->action->execute(($this->field)(FieldType::TextShort));
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        expect($errors)->toBeArray()
            ->and($errors)->toHaveKey('field');
    });
});

describe('FieldRecomputesController refuses a field of another object type', function (): void {
    test('a field that belongs to another object type never reaches the recompute action', function (): void {
        $action = Mockery::spy(RecomputeFieldAction::class);
        $controller = new FieldRecomputesController($action, new FieldOwnershipGuard);

        $foreign = ($this->field)(FieldType::Computed, ModelStub::ulid('another-object-type'));

        expect(fn (): mixed => $controller->store($this->objectType, $foreign))
            ->toThrow(NotFoundHttpException::class);

        $action->shouldNotHaveReceived('execute');
    });

    test('a field of the object type it was asked for is handed to the recompute action', function (): void {
        $field = ($this->field)(FieldType::Computed);

        $action = Mockery::spy(RecomputeFieldAction::class);
        $action->shouldReceive('execute')->once()->with($field)->andReturn(null);

        $controller = new FieldRecomputesController($action, new FieldOwnershipGuard);

        $response = $controller->store($this->objectType, $field);

        expect($response->getStatusCode())->toBe(200)
            ->and(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR))
            ->toBe(['run' => null]);
    });
});
