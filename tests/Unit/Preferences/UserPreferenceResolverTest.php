<?php

declare(strict_types=1);

use App\Support\Preferences\PreferencePolicyResolver;
use App\Support\Preferences\PreferenceSchema;
use App\Support\Preferences\UserPreferenceResolver;
use Illuminate\Support\Str;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->policies = new PreferencePolicyResolver;
    $this->resolver = new UserPreferenceResolver(new PreferenceSchema, $this->policies);

    $this->typeA = (string) Str::ulid();
    $this->typeB = (string) Str::ulid();
});

it('answers an untouched document with the global defaults and empty maps', function (): void {
    $document = $this->resolver->apply([], $this->policies->withDefaults([]));

    expect($document['settings'])->toBe([
        'appearance' => 'system',
        'density' => 'compact',
        'pageSize' => 100,
        'sidebarOpen' => true,
        'startObjectTypeId' => null,
    ])
        ->and($document['objectTypes'])->toBe([])
        ->and($document['grids'])->toBe([]);
});

it('lays a stored global setting over the defaults without dropping its neighbours', function (): void {
    $document = $this->resolver->apply(
        ['settings' => ['density' => 'comfortable']],
        $this->policies->withDefaults([]),
    );

    expect($document['settings']['density'])->toBe('comfortable')
        ->and($document['settings']['pageSize'])->toBe(100);
});

it('keeps two object types on separate slices and fills each missing key with null', function (): void {
    $document = $this->resolver->apply([
        'objectTypes' => [
            $this->typeA => ['columnState' => [['colId' => 'name']]],
            $this->typeB => ['viewMode' => 'kanban'],
        ],
    ], $this->policies->withDefaults([]));

    expect($document['objectTypes'][$this->typeA]['columnState'])->toBe([['colId' => 'name']])
        ->and($document['objectTypes'][$this->typeA]['viewMode'])->toBeNull()
        ->and($document['objectTypes'][$this->typeB]['viewMode'])->toBe('kanban')
        ->and($document['objectTypes'][$this->typeB]['columnState'])->toBeNull();
});

it('hides a stored value again once its category is switched off', function (): void {
    $stored = ['objectTypes' => [$this->typeA => ['columnState' => [['colId' => 'name']]]]];

    $off = $this->resolver->apply($stored, $this->policies->withDefaults([
        'columnsAndSorting' => ['records' => false, 'configuration' => true],
    ]));

    expect($off['objectTypes'][$this->typeA]['columnState'])->toBeNull();
});

it('returns a value stored earlier once its category is switched back on', function (): void {
    $stored = ['objectTypes' => [$this->typeA => ['columnState' => [['colId' => 'name']]]]];

    $on = $this->resolver->apply($stored, $this->policies->withDefaults([
        'columnsAndSorting' => ['records' => true, 'configuration' => true],
    ]));

    expect($on['objectTypes'][$this->typeA]['columnState'])->toBe([['colId' => 'name']]);
});

it('withholds a configuration grid whose own switch is off while records stay untouched', function (): void {
    $document = $this->resolver->apply([
        'grids' => ['roles' => ['columnState' => [['colId' => 'name']]]],
        'objectTypes' => [$this->typeA => ['columnState' => [['colId' => 'name']]]],
    ], $this->policies->withDefaults(['columnsAndSorting' => ['records' => true, 'configuration' => false]]));

    expect($document['grids']['roles']['columnState'])->toBeNull()
        ->and($document['objectTypes'][$this->typeA]['columnState'])->toBe([['colId' => 'name']]);
});

it('switching the collapsed panels off leaves the hidden ones alone', function (): void {
    $document = $this->resolver->apply([
        'objectTypes' => [$this->typeA => [
            'collapsedSections' => ['relations'],
            'hiddenSections' => ['relations'],
        ]],
    ], $this->policies->withDefaults(['panelState' => ['records' => false]]));

    expect($document['objectTypes'][$this->typeA]['collapsedSections'])->toBeNull()
        ->and($document['objectTypes'][$this->typeA]['hiddenSections'])->toBe(['relations']);
});

it('ignores a stored scope that is not a map', function (): void {
    $document = $this->resolver->apply(['settings' => 'broken'], $this->policies->withDefaults([]));

    expect($document['settings']['density'])->toBe('compact');
});
