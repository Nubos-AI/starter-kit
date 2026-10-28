<?php

declare(strict_types=1);

use App\Enums\Ui\NavIcon;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable():list<string> */
    $this->mappedIconKeys = static function (): array {
        $source = (string) file_get_contents(resource_path('js/lib/navIcons.ts'));

        preg_match('/NAV_ICONS[^=]*=\s*\{(.*?)\n\};/s', $source, $block);
        preg_match_all("/^\s+'?([a-z0-9-]+)'?:/m", $block[1] ?? '', $matches);

        return array_values(array_unique($matches[1] ?? []));
    };

    /** @var list<string> */
    $this->declaredIconKeys = array_map(static fn (NavIcon $icon): string => $icon->value, NavIcon::cases());
});

it('registers every navigation icon the backend can send in the frontend icon map', function (): void {
    $mapped = ($this->mappedIconKeys)();

    expect($mapped)->not->toBeEmpty();

    foreach ($this->declaredIconKeys as $key) {
        expect($mapped)->toContain($key);
    }
});

it('declares every key of the frontend icon map as a navigation icon', function (): void {
    $mapped = ($this->mappedIconKeys)();

    expect($mapped)->not->toBeEmpty();

    foreach ($mapped as $key) {
        expect($this->declaredIconKeys)->toContain($key);
    }
});

it('gives every navigation icon a label the configuration screen can show', function (): void {
    foreach (NavIcon::cases() as $icon) {
        expect($icon->label())->not->toBe('')
            ->and($icon->label())->not->toBe($icon->value);
    }
});
