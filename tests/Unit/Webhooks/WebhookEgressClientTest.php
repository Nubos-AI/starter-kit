<?php

declare(strict_types=1);

use App\DTOs\Webhooks\WebhookBasicAuth;
use App\Exceptions\Webhooks\SsrfBlockedException;
use App\Support\Webhooks\SsrfGuard;
use App\Support\Webhooks\WebhookEgressClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['automation.http' => [
        'allowed_hosts' => [],
        'timeout' => 5,
        'max_redirects' => 3,
    ]]);

    $this->rawBody = '{"event":"record.created","data":{"z":1,"a":"/slash/"},"nonce":"  spaced  "}';

    /** @var callable(array<string, list<string>>):void */
    $this->resolvingTo = static function (array $resolveMap): void {
        app()->instance(SsrfGuard::class, new SsrfGuard(
            static fn (string $host): array => $resolveMap[$host] ?? [$host],
        ));
    };

    /** @var callable(list<list<string>>):void */
    $this->resolvingInSequence = static function (array $sequence): void {
        $index = 0;

        app()->instance(SsrfGuard::class, new SsrfGuard(function (string $host) use ($sequence, &$index): array {
            $ips = $sequence[min($index, count($sequence) - 1)];
            $index++;

            return $ips;
        }));
    };

    $this->client = static fn (): WebhookEgressClient => app(WebhookEgressClient::class);
});

it('delivers to a public https target and hands the response back', function (): void {
    Http::fake(['hooks.customer.test/*' => Http::response(['ok' => true], 200)]);
    ($this->resolvingTo)(['hooks.customer.test' => ['93.184.216.34']]);

    $response = ($this->client)()->post('https://hooks.customer.test/hook', $this->rawBody, []);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->status())->toBe(200);

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'hooks.customer.test'));
});

it('delivers to a host that is not on the outbound allowlist because that list governs automations only', function (): void {
    Http::fake(['not-allowlisted.customer.test/*' => Http::response('', 204)]);
    ($this->resolvingTo)(['not-allowlisted.customer.test' => ['93.184.216.34']]);

    expect(($this->client)()->post('https://not-allowlisted.customer.test/hook', $this->rawBody, [])->status())->toBe(204);

    Http::assertSentCount(1);
});

it('pins the connection to the address the guard validated', function (string $url, string $ip, string $entry): void {
    $captured = [];

    Http::fake(function (Request $request, array $options) use (&$captured): PromiseInterface|Response {
        $captured[] = $options['curl'] ?? null;

        return Http::response('', 200);
    });
    ($this->resolvingTo)(['hooks.customer.test' => [$ip]]);

    ($this->client)()->post($url, $this->rawBody, []);

    expect($captured[0])->toBe([CURLOPT_RESOLVE => [$entry]]);
})->with([
    'https default port' => ['https://hooks.customer.test/hook', '93.184.216.34', 'hooks.customer.test:443:93.184.216.34'],
    'explicit port' => ['https://hooks.customer.test:8443/hook', '198.51.100.7', 'hooks.customer.test:8443:198.51.100.7'],
]);

it('caps how long a slow target may hold the connection', function (): void {
    $captured = [];

    Http::fake(function (Request $request, array $options) use (&$captured): PromiseInterface|Response {
        $captured[] = $options;

        return Http::response('', 200);
    });
    ($this->resolvingTo)(['hooks.customer.test' => ['93.184.216.34']]);

    ($this->client)()->post('https://hooks.customer.test/hook', $this->rawBody, []);

    expect($captured[0]['timeout'])->toBe(10);
});

it('sends the signed body byte for byte and the supplied headers unchanged', function (): void {
    Http::fake(['hooks.customer.test/*' => Http::response('', 200)]);
    ($this->resolvingTo)(['hooks.customer.test' => ['93.184.216.34']]);

    ($this->client)()->post('https://hooks.customer.test/hook', $this->rawBody, [
        'Content-Type' => 'application/json',
        'X-Webhook-Signature' => 't=1752710400,v1=deadbeef',
        'X-Webhook-Id' => '01JABCDEF0123456789ABCDEFG',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->body() === $this->rawBody
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('X-Webhook-Signature', 't=1752710400,v1=deadbeef')
        && $request->hasHeader('X-Webhook-Id', '01JABCDEF0123456789ABCDEFG'));
});

it('never carries the basic auth password in the url', function (): void {
    Http::fake(['hooks.customer.test/*' => Http::response('', 200)]);
    ($this->resolvingTo)(['hooks.customer.test' => ['93.184.216.34']]);

    ($this->client)()->post(
        'https://hooks.customer.test/hook',
        $this->rawBody,
        [],
        new WebhookBasicAuth('integration', 'top-secret'),
    );

    Http::assertSent(static fn (Request $request): bool => !str_contains($request->url(), 'top-secret')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('integration:top-secret')));
});

it('refuses a plain http target before anything leaves the process', function (): void {
    Http::fake();
    ($this->resolvingTo)(['hooks.customer.test' => ['93.184.216.34']]);

    expect(fn (): Response => ($this->client)()->post('http://hooks.customer.test/hook', $this->rawBody, []))
        ->toThrow(SsrfBlockedException::class);

    Http::assertNothingSent();
});

it('refuses an internal target before anything leaves the process', function (string $url): void {
    Http::fake();
    ($this->resolvingTo)([]);

    expect(fn (): Response => ($this->client)()->post($url, $this->rawBody, []))
        ->toThrow(SsrfBlockedException::class);

    Http::assertNothingSent();
})->with([
    'cloud metadata link-local' => ['https://169.254.169.254/latest/meta-data'],
    'loopback' => ['https://127.0.0.1/hook'],
    'rfc1918 10/8' => ['https://10.0.0.7/hook'],
    'rfc1918 192.168/16' => ['https://192.168.1.10/hook'],
    'ipv6 loopback' => ['https://[::1]/hook'],
    'ipv6-mapped loopback' => ['https://[::ffff:127.0.0.1]/hook'],
    'decimal-encoded loopback' => ['https://2130706433/hook'],
    'carrier-grade nat 100.64/10' => ['https://100.64.0.1/hook'],
    'carrier-grade nat upper bound' => ['https://100.127.255.254/hook'],
    'nat64 prefix 64:ff9b::/96' => ['https://[64:ff9b::a9fe:a9fe]/hook'],
    '6to4 prefix 2002::/16' => ['https://[2002:a00:7::1]/hook'],
]);

it('refuses a public host that rebinds to a private address', function (): void {
    Http::fake();
    ($this->resolvingTo)(['hooks.customer.test' => ['10.0.0.7']]);

    expect(fn (): Response => ($this->client)()->post('https://hooks.customer.test/hook', $this->rawBody, []))
        ->toThrow(SsrfBlockedException::class);

    Http::assertNothingSent();
});

it('never follows a redirect into an internal address', function (): void {
    Http::fake([
        'hooks.customer.test/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data']),
        '169.254.169.254/*' => Http::response('unreachable', 200),
    ]);
    ($this->resolvingTo)(['hooks.customer.test' => ['93.184.216.34']]);

    expect(($this->client)()->post('https://hooks.customer.test/hook', $this->rawBody, [])->status())->toBe(302);

    Http::assertSentCount(1);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
});

it('re-runs the guard on every call so a target turning private is blocked on the next one', function (): void {
    Http::fake(['hooks.customer.test/*' => Http::response('', 200)]);
    ($this->resolvingInSequence)([['93.184.216.34'], ['10.0.0.7']]);

    expect(($this->client)()->post('https://hooks.customer.test/hook', $this->rawBody, [])->status())->toBe(200)
        ->and(fn (): Response => ($this->client)()->post('https://hooks.customer.test/hook', $this->rawBody, []))
        ->toThrow(SsrfBlockedException::class);

    Http::assertSentCount(1);
});
