<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FieldTypeCategory;
use Tests\TestCase;

uses(TestCase::class);

test('every category holds at least one field type', function (): void {
    $used = array_map(
        static fn (FieldType $type): FieldTypeCategory => $type->category(),
        FieldType::cases(),
    );

    foreach (FieldTypeCategory::cases() as $category) {
        expect(in_array($category, $used, true))->toBeTrue($category->name.' holds no field type');
    }
});

test('the calculated category groups the two derived field types', function (): void {
    expect(FieldType::Computed->category())->toBe(FieldTypeCategory::Calculated)
        ->and(FieldType::Rollup->category())->toBe(FieldTypeCategory::Calculated);
});
