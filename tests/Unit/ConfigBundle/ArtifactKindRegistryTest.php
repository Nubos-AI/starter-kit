<?php

declare(strict_types=1);

use App\Enums\ConfigBundle\ArtifactKind;
use App\Support\ConfigBundle\ArtifactKindRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['engine.config_bundle.kinds' => []]);

    $this->coreKinds = array_map(static fn (ArtifactKind $kind): string => $kind->value, ArtifactKind::cases());
});

it('knows only the kinds of the core while no module registers one', function (): void {
    expect(ArtifactKindRegistry::all())->toBe($this->coreKinds)
        ->and(ArtifactKindRegistry::has('object-types'))->toBeTrue()
        ->and(ArtifactKindRegistry::has('widgets'))->toBeFalse();
});

it('places a registered kind right behind its anchor and chains kinds of the same module', function (): void {
    config(['engine.config_bundle.kinds' => [
        'widgets' => ['after' => 'relationship-types'],
        'widget-parts' => ['after' => 'widgets'],
    ]]);

    $kinds = ArtifactKindRegistry::all();
    $anchor = array_search('relationship-types', $kinds, true);

    expect(array_slice($kinds, (int) $anchor, 4))->toBe(['relationship-types', 'widgets', 'widget-parts', 'field-dependencies'])
        ->and(ArtifactKindRegistry::has('widget-parts'))->toBeTrue();
});

it('appends a registered kind whose anchor is unknown and never duplicates a core kind', function (): void {
    config(['engine.config_bundle.kinds' => [
        'roles' => ['after' => 'skills'],
        'widgets' => ['after' => 'gibt-es-nicht'],
    ]]);

    expect(ArtifactKindRegistry::all())->toBe([...$this->coreKinds, 'widgets']);
});

it('translates the labels a module registers and falls back to the kind itself', function (): void {
    app('translator')->addLines([
        'i18n.widgets_plural' => 'Widgets',
        'i18n.widgets_singular' => 'das Widget',
    ], app()->getLocale());

    config(['engine.config_bundle.kinds' => [
        'widgets' => ['label' => 'i18n.widgets_plural', 'singular_label' => 'i18n.widgets_singular'],
        'gadgets' => [],
    ]]);

    expect(ArtifactKindRegistry::label('widgets'))->toBe('Widgets')
        ->and(ArtifactKindRegistry::singularLabel('widgets'))->toBe('das Widget')
        ->and(ArtifactKindRegistry::label('gadgets'))->toBe('gadgets')
        ->and(ArtifactKindRegistry::labels()['widgets'])->toBe('Widgets')
        ->and(ArtifactKindRegistry::label('object-types'))->toBe(ArtifactKind::ObjectTypes->label());
});

it('joins kind and key into the canonical identifier', function (): void {
    expect(ArtifactKindRegistry::identifierFor('widgets', 'deal:main'))->toBe('widgets:deal:main');
});
