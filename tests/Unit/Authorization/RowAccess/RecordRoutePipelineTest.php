<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\MiddlewarePipeline;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->team = AccessContext::team($this->tenant);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->enforcement = app(RowAccessEnforcement::class);
    $this->enforcement->disable();

    $this->enforcementDuringBinding = null;

    Route::bind('record', function (string $value): CustomRecord {
        $this->enforcementDuringBinding = $this->enforcement->isEnabled();

        return $this->record;
    });

    $this->recordRequest = fn (): Request => RouteShape::named('engine.records.update')->request(
        '/'.$this->team->getKey().'/engine/records/'.$this->record->getKey(),
        'PUT',
    );
});

afterEach(function (): void {
    app(RowAccessEnforcement::class)->disable();
    AccessContext::forgetTenant();
    AccessContext::forgetTeam();
});

it('runs enforcement and binding in the order the route declares', function (): void {
    $pipeline = MiddlewarePipeline::forRoute('engine.records.update', [
        EnforceRecordAccessRules::class,
        SubstituteBindings::class,
    ]);

    expect($pipeline->middleware())->toBe([EnforceRecordAccessRules::class, SubstituteBindings::class]);
});

it('has row access enforcement switched on before the record binding is resolved', function (): void {
    MiddlewarePipeline::forRoute('engine.records.update', [
        EnforceRecordAccessRules::class,
        SubstituteBindings::class,
    ])->send(($this->recordRequest)(), static fn (): Response => new Response('reached'));

    expect($this->enforcementDuringBinding)->toBeTrue();
});

it('leaves enforcement off when the middleware is not part of the chain', function (): void {
    MiddlewarePipeline::through([SubstituteBindings::class])
        ->send(($this->recordRequest)(), static fn (): Response => new Response('reached'));

    expect($this->enforcementDuringBinding)->toBeFalse();
});
