<?php

declare(strict_types=1);

use Inertia\DevTools\DevTools;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('keeps the inertia devtools recorder off in the local environment unless it is switched on', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    expect(DevTools::enabled())->toBeFalse();
});
