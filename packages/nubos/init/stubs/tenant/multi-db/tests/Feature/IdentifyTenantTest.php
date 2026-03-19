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

it('configures tenant database connection on identification', function (): void {
    config(['app.url' => 'http://example.com']);

    $tenant = Tenant::factory()->create([
        'slug' => 'acme',
        'database' => 'tenant_acme',
    ]);

    $this->get('/', ['Host' => 'acme.example.com']);

    expect(config('database.connections.tenant.database'))->toBe('tenant_acme');
});

it('returns 404 for unknown tenant subdomain', function (): void {
    config(['app.url' => 'http://example.com']);

    $this->get('/', ['Host' => 'unknown.example.com'])
        ->assertNotFound();
});

it('redirects to fallback when host has no subdomain', function (): void {
    config([
        'app.url' => 'http://example.com',
        'nubos.tenant_fallback_url' => 'https://www.example.com',
    ]);

    $this->get('/', ['Host' => 'example.com'])
        ->assertRedirect('https://www.example.com');
});

it('redirects reserved subdomains to fallback', function (): void {
    config([
        'app.url' => 'http://example.com',
        'nubos.tenant_fallback_url' => 'https://www.example.com',
        'nubos.reserved_subdomains' => ['www', 'docs', 'api'],
    ]);

    $this->get('/', ['Host' => 'www.example.com'])
        ->assertRedirect('https://www.example.com');
});

it('redirects to fallback for unrelated domain', function (): void {
    config([
        'app.url' => 'http://example.com',
        'nubos.tenant_fallback_url' => 'https://www.example.com',
    ]);

    Tenant::factory()->create(['slug' => 'acme']);

    $this->get('/', ['Host' => 'acme.other-domain.com'])
        ->assertRedirect('https://www.example.com');
});
