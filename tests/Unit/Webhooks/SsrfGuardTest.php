<?php

declare(strict_types=1);

use App\Exceptions\Webhooks\SsrfBlockedException;
use App\Support\Webhooks\SsrfGuard;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(list<string>):SsrfGuard */
    $this->guardResolving = static fn (array $ips): SsrfGuard => new SsrfGuard(static fn (string $host): array => $ips);
});

it('blocks a webhook target whose hostname resolves into a private or reserved range', function (string $ip): void {
    $guard = ($this->guardResolving)([$ip]);

    expect(fn (): string => $guard->assertWebhookTargetAllowed('https://rebinding.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class);
})->with([
    'private class a' => ['10.0.0.5'],
    'private class c' => ['192.168.1.10'],
    'loopback' => ['127.0.0.1'],
    'cloud metadata' => ['169.254.169.254'],
    'ipv6 loopback' => ['::1'],
    'ipv6 unique local' => ['fd00::1'],
    'carrier grade nat' => ['100.64.0.1'],
    'nat64 well known prefix' => ['64:ff9b::7f00:1'],
    'ipv4 mapped loopback' => ['::ffff:127.0.0.1'],
    'ipv4 compatible loopback in hex' => ['::7f00:1'],
]);

it('blocks a literal internal address before it ever resolves a name', function (string $url): void {
    $guard = new SsrfGuard(static fn (string $host): array => ['203.0.113.7']);

    expect(fn (): string => $guard->assertWebhookTargetAllowed($url))
        ->toThrow(SsrfBlockedException::class);
})->with([
    'plain loopback' => ['https://127.0.0.1/inbox'],
    'decimal encoded loopback' => ['https://2130706433/inbox'],
    'hex encoded loopback' => ['https://0x7f.0x0.0x0.0x1/inbox'],
    'octal encoded loopback' => ['https://0177.0.0.01/inbox'],
    'bracketed ipv6 loopback' => ['https://[::1]/inbox'],
    'metadata service' => ['http://169.254.169.254/latest/meta-data/'],
    'file scheme' => ['file:///etc/passwd'],
    'credentials in the address' => ['https://root:secret@hooks.example.com/inbox'],
]);

it('pins the public address it validated and blocks a host that also resolves internally', function (): void {
    expect(($this->guardResolving)(['93.184.216.34'])->assertWebhookTargetAllowed('https://hooks.example.com/inbox'))
        ->toBe('93.184.216.34')
        ->and(fn (): string => ($this->guardResolving)(['93.184.216.34', '10.1.2.3'])->assertWebhookTargetAllowed('https://hooks.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class);
});

it('pins a resolved host for curl and leaves a literal address alone, which curl would refuse to parse for ipv6', function (): void {
    $guard = new SsrfGuard(static fn (string $host): array => ['2001:db8::7']);

    expect($guard->resolveEntriesFor('https://hooks.example.com/inbox', '2001:db8::7'))->toBe(['hooks.example.com:443:2001:db8::7'])
        ->and($guard->resolveEntriesFor('http://hooks.example.com:8080/inbox', '93.184.216.34'))->toBe(['hooks.example.com:8080:93.184.216.34'])
        ->and($guard->resolveEntriesFor('https://[2001:db8::7]/inbox', '2001:db8::7'))->toBe([])
        ->and($guard->resolveEntriesFor('https://93.184.216.34/inbox', '93.184.216.34'))->toBe([]);
});
