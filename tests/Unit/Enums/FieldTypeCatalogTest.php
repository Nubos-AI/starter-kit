<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FieldTypeCategory;
use Tests\TestCase;

uses(TestCase::class);

test('every field type is filed under a category and explains itself', function (): void {
    foreach (FieldType::cases() as $case) {
        expect($case->category())->toBeInstanceOf(FieldTypeCategory::class)
            ->and($case->description())->not->toBe('', $case->name.' has no description');
    }
});

test('every category carries a label and holds at least one field type', function (): void {
    $used = array_map(
        static fn (FieldType $type): FieldTypeCategory => $type->category(),
        FieldType::cases(),
    );

    foreach (FieldTypeCategory::cases() as $category) {
        expect($category->label())->not->toBe('', $category->name.' has no label')
            ->and(in_array($category, $used, true))->toBeTrue($category->name.' holds no field type');
    }
});

test('the calculated category groups the two derived field types', function (): void {
    expect(FieldType::Computed->category())->toBe(FieldTypeCategory::Calculated)
        ->and(FieldType::Rollup->category())->toBe(FieldTypeCategory::Calculated)
        ->and(FieldTypeCategory::Calculated->label())->toBe('Berechnet');
});
