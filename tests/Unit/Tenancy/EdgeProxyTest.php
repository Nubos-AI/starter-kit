<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->forwarded = function (): Request {
        $request = Request::create('http://acme.nubos.test/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]);

        return app(TrustProxies::class)->handle($request, static fn (Request $passed): Request => $passed);
    };
});

afterEach(function (): void {
    TrustProxies::flushState();
});

it('treats a request forwarded by the configured edge proxy as secure', function (): void {
    config(['tenancy.edge.trusted_proxies' => '172.16.0.0/12, 10.0.0.0/8']);

    (new AppServiceProvider(app()))->configureTrustedProxies();

    expect(($this->forwarded)()->isSecure())->toBeTrue();
});

it('ignores forwarded headers while no edge proxy is configured', function (): void {
    config(['tenancy.edge.trusted_proxies' => '']);

    (new AppServiceProvider(app()))->configureTrustedProxies();

    expect(($this->forwarded)()->isSecure())->toBeFalse();
});

it('lets caddy ask the application before it requests a certificate for a host', function (): void {
    $caddyfile = (string) file_get_contents(base_path('docker/caddy/Caddyfile'));

    expect($caddyfile)->toContain('ask http://laravel.test'.route('tenancy.tls.ask', absolute: false).'?token={$TENANT_TLS_ASK_TOKEN}')
        ->toContain('on_demand')
        ->toContain('reverse_proxy laravel.test:80');
});

it('puts caddy in front of the application in the production stack and keeps the application off the public ports', function (): void {
    $stack = Yaml::parseFile(base_path('compose.production.yaml'), Yaml::PARSE_CUSTOM_TAGS);
    $caddy = $stack['services']['caddy'];

    expect($caddy['image'])->toMatch('/^caddy:\d+\.\d+\.\d+$/')
        ->and($caddy['ports'])->toBe(['80:80', '443:443', '443:443/udp'])
        ->and($caddy['volumes'])->toContain('./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro')
        ->and($caddy['environment'])->toHaveKeys(['TENANT_TLS_ASK_TOKEN', 'TLS_ACME_EMAIL'])
        ->and($caddy['depends_on'])->toBe(['laravel.test'])
        ->and($stack['services']['laravel.test']['ports']->getTag())->toBe('reset');
});
