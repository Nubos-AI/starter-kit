<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves tenant from subdomain', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    $response = $this->get('/', ['Host' => 'acme.example.com']);

    expect(app()->bound('currentTenant'))->toBeTrue()
        ->and(app('currentTenant')->id)->toBe($tenant->id);
});

it('continues without tenant when host does not match', function (): void {
    config(['app.url' => 'http://example.com']);

    $response = $this->get('/', ['Host' => 'example.com']);

    expect(app()->bound('currentTenant'))->toBeFalse();
});

it('does not resolve subdomain from unrelated domain', function (): void {
    config(['app.url' => 'http://example.com']);

    Tenant::factory()->create(['slug' => 'acme']);

    $response = $this->get('/', ['Host' => 'acme.other-domain.com']);

    expect(app()->bound('currentTenant'))->toBeFalse();
});
