<?php

declare(strict_types=1);

use App\Support\Preferences\PreferenceSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = new PreferenceSchema;

    /** @var callable(array<string, mixed>):list<string> */
    $this->rejectionsFor = function (array $payload): array {
        try {
            Validator::make($payload, $this->schema->rules($payload))->validate();
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    };

    $this->objectTypeId = (string) Str::ulid();
});

it('accepts a global setting, an object type slice and a configuration grid together', function (): void {
    $rejections = ($this->rejectionsFor)([
        'settings' => ['density' => 'comfortable', 'pageSize' => 25],
        'objectTypes' => [$this->objectTypeId => ['viewMode' => 'kanban', 'columnState' => [['colId' => 'name']]]],
        'grids' => ['roles' => ['columnState' => []]],
    ]);

    expect($rejections)->toBe([]);
});

it('rejects a scope the application does not know and still demands a known one', function (): void {
    expect(($this->rejectionsFor)(['dashboards' => ['foo' => 'bar']]))->toBe(['settings', 'dashboards']);
});

it('rejects an unknown key inside a known scope', function (): void {
    expect(($this->rejectionsFor)(['settings' => ['wallpaper' => 'blue']]))->toBe(['settings.wallpaper']);
});

it('rejects an object type key that is not a ulid', function (): void {
    expect(($this->rejectionsFor)(['objectTypes' => ['companies' => ['viewMode' => 'kanban']]]))
        ->toBe(['objectTypes.companies']);
});

it('rejects a configuration grid that is not part of the catalogue', function (): void {
    expect(($this->rejectionsFor)(['grids' => ['wallpapers' => ['columnState' => []]]]))
        ->toBe(['grids.wallpapers']);
});

it('rejects a view mode outside the record view modes', function (): void {
    expect(($this->rejectionsFor)(['objectTypes' => [$this->objectTypeId => ['viewMode' => 'gallery']]]))
        ->toBe(['objectTypes.'.$this->objectTypeId.'.viewMode']);
});

it('rejects a non string entry in the collapsed and hidden panel lists', function (): void {
    expect(($this->rejectionsFor)(['objectTypes' => [$this->objectTypeId => ['collapsedSections' => [['nope']]]]]))
        ->toBe(['objectTypes.'.$this->objectTypeId.'.collapsedSections.0'])
        ->and(($this->rejectionsFor)(['objectTypes' => [$this->objectTypeId => ['hiddenSections' => [['nope']]]]]))
        ->toBe(['objectTypes.'.$this->objectTypeId.'.hiddenSections.0']);
});

it('rejects an empty payload by demanding the settings scope', function (): void {
    expect(($this->rejectionsFor)([]))->toBe(['settings']);
});

it('rejects a page size outside the supported range', function (): void {
    expect(($this->rejectionsFor)(['settings' => ['pageSize' => 0]]))->toBe(['settings.pageSize'])
        ->and(($this->rejectionsFor)(['settings' => ['pageSize' => 1001]]))->toBe(['settings.pageSize']);
});

it('rejects a start object type that is not a ulid', function (): void {
    expect(($this->rejectionsFor)(['settings' => ['startObjectTypeId' => 'companies']]))
        ->toBe(['settings.startObjectTypeId']);
});

it('fills every object type and grid key with a null default', function (): void {
    expect($this->schema->objectTypeDefaults())->toBe([
        'viewMode' => null,
        'kanbanAxis' => null,
        'kanbanPipeline' => null,
        'hierarchyOrder' => null,
        'columnState' => null,
        'lastSegmentId' => null,
        'lastFilter' => null,
        'collapsedSections' => null,
        'hiddenSections' => null,
    ])
        ->and($this->schema->gridDefaults())->toBe(['columnState' => null]);
});

it('answers an untouched document with the global defaults', function (): void {
    expect($this->schema->globalDefaults())->toBe([
        'appearance' => 'system',
        'density' => 'compact',
        'pageSize' => 100,
        'sidebarOpen' => true,
        'startObjectTypeId' => null,
    ]);
});
