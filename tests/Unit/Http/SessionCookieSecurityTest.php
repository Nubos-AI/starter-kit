<?php

declare(strict_types=1);

use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->previousEnvironment = getenv('APP_ENV');
    $this->previousSecureCookie = getenv('SESSION_SECURE_COOKIE');

    $this->sessionConfigWith = function (string $environment, ?string $secureCookie): array {
        putenv("APP_ENV={$environment}");
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;

        if ($secureCookie === null) {
            putenv('SESSION_SECURE_COOKIE');
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
        } else {
            putenv("SESSION_SECURE_COOKIE={$secureCookie}");
            $_ENV['SESSION_SECURE_COOKIE'] = $_SERVER['SESSION_SECURE_COOKIE'] = $secureCookie;
        }

        return require base_path('config/session.php');
    };
});

afterEach(function (): void {
    foreach (['APP_ENV' => $this->previousEnvironment, 'SESSION_SECURE_COOKIE' => $this->previousSecureCookie] as $name => $value) {
        if ($value === false) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
});

it('sends the session cookie only over https in production unless it is configured otherwise', function (string $environment, ?string $secureCookie, bool $isSecure): void {
    expect(($this->sessionConfigWith)($environment, $secureCookie)['secure'])->toBe($isSecure);
})->with([
    'production without a setting' => ['production', null, true],
    'production switched off explicitly' => ['production', 'false', false],
    'local without a setting' => ['local', null, false],
    'local switched on explicitly' => ['local', 'true', true],
]);
