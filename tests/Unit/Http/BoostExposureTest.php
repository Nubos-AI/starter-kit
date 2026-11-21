<?php

declare(strict_types=1);

use Tests\Support\EnvironmentVariables;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->environment = new EnvironmentVariables;
});

afterEach(function (): void {
    $this->environment->restore();
});

it('keeps the boost endpoints off outside a local development host, even when debug mode was left on', function (string $environment, ?string $switch, bool $isEnabled): void {
    $this->environment->set(['APP_ENV' => $environment, 'APP_DEBUG' => 'true', 'BOOST_ENABLED' => $switch]);

    expect($this->environment->config('boost')['enabled'] ?? true)->toBe($isEnabled);
})->with([
    'staging without a setting' => ['staging', null, false],
    'production without a setting' => ['production', null, false],
    'local without a setting' => ['local', null, true],
    'local switched off explicitly' => ['local', 'false', false],
]);
