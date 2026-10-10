<?php

declare(strict_types=1);

use App\Enums\Preferences\PreferenceArea;
use App\Enums\Preferences\PreferenceCategory;
use App\Enums\Preferences\PreferenceScope;
use App\Support\Preferences\PreferencePolicyResolver;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->policies = new PreferencePolicyResolver;
});

it('enables every category except filter and segment when nothing is stored', function (): void {
    expect($this->policies->withDefaults([]))->toBe([
        'columnsAndSorting' => ['records' => true, 'configuration' => true],
        'viewMode' => ['records' => true],
        'filterAndSegment' => ['records' => false],
        'layoutAndAppearance' => ['global' => true],
        'panelState' => ['records' => true],
        'panelVisibility' => ['records' => true],
    ]);
});

it('takes the stored switch over the default and keeps the untouched areas on their default', function (): void {
    $policy = $this->policies->withDefaults([
        'columnsAndSorting' => ['records' => false],
        'filterAndSegment' => ['records' => true],
    ]);

    expect($policy['columnsAndSorting'])->toBe(['records' => false, 'configuration' => true])
        ->and($policy['filterAndSegment'])->toBe(['records' => true]);
});

it('ignores a stored category that is not a matrix', function (): void {
    expect($this->policies->withDefaults(['columnsAndSorting' => 'yes'])['columnsAndSorting'])
        ->toBe(['records' => true, 'configuration' => true]);
});

it('falls back to the category default when the resolved matrix does not carry the area', function (): void {
    expect($this->policies->allows([], PreferenceCategory::FilterAndSegment, PreferenceArea::Records))->toBeFalse()
        ->and($this->policies->allows([], PreferenceCategory::PanelState, PreferenceArea::Records))->toBeTrue();
});

it('drops exactly the object type keys whose category is switched off', function (): void {
    $policy = $this->policies->withDefaults([
        'columnsAndSorting' => ['records' => false],
        'panelState' => ['records' => false],
    ]);

    $filtered = $this->policies->filterSlice($policy, PreferenceScope::ObjectTypes, [
        'columnState' => [['colId' => 'name']],
        'collapsedSections' => ['relations'],
        'hiddenSections' => ['relations'],
        'viewMode' => 'kanban',
    ]);

    expect($filtered)->toBe(['hiddenSections' => ['relations'], 'viewMode' => 'kanban']);
});

it('measures a configuration grid against the configuration area, not the record area', function (): void {
    $policy = $this->policies->withDefaults(['columnsAndSorting' => ['records' => true, 'configuration' => false]]);

    expect($this->policies->filterSlice($policy, PreferenceScope::Grids, ['columnState' => [['colId' => 'name']]]))
        ->toBe([])
        ->and($this->policies->filterSlice($policy, PreferenceScope::ObjectTypes, ['columnState' => [['colId' => 'name']]]))
        ->toBe(['columnState' => [['colId' => 'name']]]);
});

it('keeps the last used segment out of a slice while the filter category defaults to off', function (): void {
    $policy = $this->policies->withDefaults([]);

    expect($this->policies->filterSlice($policy, PreferenceScope::ObjectTypes, [
        'lastSegmentId' => '01JBRT0000000000000000000A',
        'lastFilter' => ['field' => 'name'],
    ]))->toBe([]);
});

it('measures every global setting against the layout category on the global area', function (): void {
    $policy = $this->policies->withDefaults(['layoutAndAppearance' => ['global' => false]]);

    expect($this->policies->filterSlice($policy, PreferenceScope::Settings, [
        'appearance' => 'dark',
        'density' => 'comfortable',
        'pageSize' => 25,
        'sidebarOpen' => false,
        'startObjectTypeId' => null,
    ]))->toBe([]);
});
