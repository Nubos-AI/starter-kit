<?php

declare(strict_types=1);

use App\Http\Middleware\TrustPlatformHosts;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\MiddlewarePipeline;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config([
        'app.url' => 'https://app.nubos.test',
        'tenancy.welcome.base_domain' => 'nubos.cloud',
        'security.trusted_hosts' => ['laravel.test', 'localhost', '127.0.0.1'],
    ]);

    app()['env'] = 'production';

    $this->certifiable = [];
    $this->askedResolverFor = [];

    $resolver = Mockery::mock(WelcomeTenantResolver::class);
    $resolver->shouldReceive('isCertifiable')->andReturnUsing(function (string $host): bool {
        $this->askedResolverFor[] = $host;

        return in_array($host, $this->certifiable, true);
    });
    app()->instance(WelcomeTenantResolver::class, $resolver);

    $this->reached = false;

    $this->send = fn (string $url): Response => MiddlewarePipeline::through([TrustPlatformHosts::class])
        ->send(Request::create($url), function (Request $request): Response {
            $this->reached = true;

            return new Response($request->getHost());
        });
});

afterEach(function (): void {
    app()['env'] = 'testing';
    Request::setTrustedHosts([]);
    Mockery::close();
});

it('refuses a request whose host the platform does not serve before it reaches the application', function (string $url): void {
    $response = ($this->send)($url);

    expect($response->getStatusCode())->toBe(400)
        ->and($this->reached)->toBeFalse();
})->with([
    'a foreign host' => ['http://evil.example/register'],
    'a host that only ends like the application host' => ['http://evilapp.nubos.test/login'],
    'a host that only starts like the base domain' => ['http://nubos.cloud.evil.example/'],
    'an unverified custom domain' => ['https://shop.unverified.example/'],
]);

it('serves the application host, every platform subdomain and the configured internal hosts', function (string $url, string $host): void {
    $response = ($this->send)($url);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe($host)
        ->and($this->askedResolverFor)->toBe([]);
})->with([
    'the application host' => ['https://app.nubos.test/login', 'app.nubos.test'],
    'a subdomain of the application host' => ['https://acme.app.nubos.test/', 'acme.app.nubos.test'],
    'the base domain' => ['https://nubos.cloud/', 'nubos.cloud'],
    'a tenant subdomain of the base domain' => ['https://sales.acme.nubos.cloud/', 'sales.acme.nubos.cloud'],
    'the internal service name' => ['http://laravel.test/up', 'laravel.test'],
    'localhost' => ['http://localhost/up', 'localhost'],
    'the loopback address' => ['http://127.0.0.1/up', '127.0.0.1'],
]);

it('serves a custom domain that a tenant or a site has verified', function (): void {
    $this->certifiable = ['shop.acme-corp.example'];

    $response = ($this->send)('https://shop.acme-corp.example/checkout');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('shop.acme-corp.example')
        ->and($this->askedResolverFor)->toBe(['shop.acme-corp.example']);
});

it('leaves the trusted host check off in local development', function (): void {
    app()['env'] = 'local';

    expect(($this->send)('http://evil.example/register')->getStatusCode())->toBe(200)
        ->and($this->reached)->toBeTrue();
});

it('releases the trusted hosts after the request so the next one on a long running worker is judged afresh', function (): void {
    ($this->send)('https://app.nubos.test/login');

    expect(Request::getTrustedHosts())->toBe([]);
});

it('runs in the global middleware stack', function (): void {
    expect(app(Kernel::class)->hasMiddleware(TrustPlatformHosts::class))->toBeTrue();
});
