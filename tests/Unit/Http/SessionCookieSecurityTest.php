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

it('sends the session cookie only over https in production unless it is configured otherwise', function (string $environment, ?string $secureCookie, bool $isSecure): void {
    $this->environment->set(['APP_ENV' => $environment, 'SESSION_SECURE_COOKIE' => $secureCookie]);

    expect($this->environment->config('session')['secure'])->toBe($isSecure);
})->with([
    'production without a setting' => ['production', null, true],
    'production switched off explicitly' => ['production', 'false', false],
    'local without a setting' => ['local', null, false],
    'local switched on explicitly' => ['local', 'true', true],
]);
