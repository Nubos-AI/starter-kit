<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
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

it('never takes the host from a forwarded header, so a client cannot change the host of absolute urls behind the edge proxy', function (): void {
    config(['tenancy.edge.trusted_proxies' => '172.16.0.0/12']);

    (new AppServiceProvider(app()))->configureTrustedProxies();

    $request = Request::create('http://acme.nubos.test/', 'GET', [], [], [], [
        'REMOTE_ADDR' => '172.18.0.5',
        'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);
    $passed = app(TrustProxies::class)->handle($request, static fn (Request $passed): Request => $passed);

    expect($passed->getHost())->toBe('acme.nubos.test')
        ->and($passed->isSecure())->toBeTrue();
});
