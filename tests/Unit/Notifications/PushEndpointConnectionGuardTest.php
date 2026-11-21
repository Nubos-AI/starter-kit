<?php

declare(strict_types=1);

use App\Support\Notifications\PushEndpointConnectionGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('aborts a push delivery whose connection landed on an internal or reserved address and logs it without the endpoint path', function (string $connectedIp): void {
    Log::spy();
    $handle = curl_init('https://push.example/secret-subscription-token');

    expect(PushEndpointConnectionGuard::allowsConnection($handle, $connectedIp, '172.18.0.2', 443, 50000))->toBe(CURL_PREREQFUNC_ABORT);

    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $context === ['connected_ip' => $connectedIp, 'endpoint_host' => 'push.example']);
})->with([
    'loopback' => ['127.0.0.1'],
    'cloud metadata' => ['169.254.169.254'],
    'private range' => ['10.0.0.5'],
    'ipv6 loopback' => ['::1'],
    'mapped ipv4 loopback' => ['::ffff:127.0.0.1'],
]);

it('lets a push delivery through whose connection landed on a public address', function (string $connectedIp): void {
    Log::spy();

    expect(PushEndpointConnectionGuard::allowsConnection(curl_init('https://push.example/abc'), $connectedIp, '172.18.0.2', 443, 50000))->toBe(CURL_PREREQFUNC_OK);

    Log::shouldNotHaveReceived('warning');
})->with([
    'ipv4' => ['93.184.216.34'],
    'ipv6' => ['2606:2800:220:1:248:1893:25c8:1946'],
]);

it('checks every connection the push client opens, so a host that resolves to an internal address at send time is never written to', function (): void {
    Log::spy();
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($server, false);
    $error = null;

    try {
        (new Client(config('webpush.client_options')))->post("http://{$address}/push", ['body' => 'payload', 'timeout' => 5]);
    } catch (GuzzleException $exception) {
        $error = $exception->getMessage();
    } finally {
        fclose($server);
    }

    expect($error)->toContain('aborted');
    Log::shouldHaveReceived('warning')->once();
});
