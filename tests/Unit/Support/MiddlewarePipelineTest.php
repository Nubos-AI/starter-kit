<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Tests\Support\MiddlewarePipeline;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('refuses to build a pipeline that silently leaves out a middleware the route does not run', function (): void {
    expect(fn (): MiddlewarePipeline => MiddlewarePipeline::forRoute('engine.records.update', [
        EnforceRecordAccessRules::class,
        ValidateSignature::class,
    ]))->toThrow(LogicException::class, ValidateSignature::class);
});

it('keeps every requested middleware the route runs in the order the route runs them', function (): void {
    expect(MiddlewarePipeline::forRoute('engine.records.update', [
        SubstituteBindings::class,
        EnforceRecordAccessRules::class,
    ])->middleware())->toBe([EnforceRecordAccessRules::class, SubstituteBindings::class]);
});
