<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->environment = app()->environment();
});

afterEach(function (): void {
    app()->instance('env', $this->environment);
});

it('refuses the api documentation to a guest on a non-production host', function (string $environment): void {
    app()->instance('env', $environment);

    expect(Gate::forUser(null)->allows('viewApiDocs'))->toBeFalse();
})->with(['staging', 'testing', 'demo']);

it('opens the api documentation to a guest on a local development host', function (): void {
    app()->instance('env', 'local');

    expect(Gate::forUser(null)->allows('viewApiDocs'))->toBeTrue();
});

it('refuses the api documentation to a guest in production', function (): void {
    app()->instance('env', 'production');

    expect(Gate::forUser(null)->allows('viewApiDocs'))->toBeFalse();
});
