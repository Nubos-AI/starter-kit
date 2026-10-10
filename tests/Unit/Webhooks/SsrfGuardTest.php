<?php

declare(strict_types=1);

use App\Exceptions\Webhooks\SsrfBlockedException;
use App\Support\Webhooks\SsrfGuard;
use Illuminate\Support\Facades\Config;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Config::set('automation.http.allowed_hosts', ['partner.example.com']);

    /** @var callable(list<string>):SsrfGuard */
    $this->guardResolving = static fn (array $ips): SsrfGuard => new SsrfGuard(static fn (string $host): array => $ips);
});

it('lets a public https webhook target through and answers with the pinned ip', function (): void {
    $guard = ($this->guardResolving)(['93.184.216.34']);

    expect($guard->assertWebhookTargetAllowed('https://hooks.example.com/inbox'))->toBe('93.184.216.34');
});

it('blocks a webhook target whose hostname resolves into a private or reserved range', function (string $ip): void {
    $guard = ($this->guardResolving)([$ip]);

    expect(fn (): string => $guard->assertWebhookTargetAllowed('https://rebinding.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class);
})->with([
    'private class a' => ['10.0.0.5'],
    'private class b' => ['172.16.9.9'],
    'private class c' => ['192.168.1.10'],
    'loopback' => ['127.0.0.1'],
    'link local' => ['169.254.10.10'],
    'cloud metadata' => ['169.254.169.254'],
    'ipv6 loopback' => ['::1'],
    'ipv6 unique local' => ['fd00::1'],
    'carrier grade nat' => ['100.64.0.1'],
    'nat64 well known prefix' => ['64:ff9b::7f00:1'],
    'six to four' => ['2002:7f00:0001::1'],
    'ipv4 mapped loopback' => ['::ffff:127.0.0.1'],
    'ipv4 mapped private' => ['::ffff:10.0.0.5'],
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
]);

it('refuses a scheme that is neither http nor https', function (string $url): void {
    $guard = ($this->guardResolving)(['93.184.216.34']);

    expect(fn (): string => $guard->assertWebhookTargetAllowed($url))
        ->toThrow(SsrfBlockedException::class);
})->with([
    'file' => ['file:///etc/passwd'],
    'gopher' => ['gopher://hooks.example.com/'],
    'ftp' => ['ftp://hooks.example.com/'],
    'missing scheme' => ['hooks.example.com/inbox'],
]);

it('refuses credentials smuggled into the address', function (): void {
    $guard = ($this->guardResolving)(['93.184.216.34']);

    expect(fn (): string => $guard->assertWebhookTargetAllowed('https://root:secret@hooks.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class);
});

it('refuses a target whose hostname resolves to nothing', function (): void {
    $guard = ($this->guardResolving)([]);

    expect(fn (): string => $guard->assertWebhookTargetAllowed('https://nowhere.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class);
});

it('blocks the target when only one of several resolved addresses is internal', function (): void {
    $guard = ($this->guardResolving)(['93.184.216.34', '10.1.2.3']);

    expect(fn (): string => $guard->assertWebhookTargetAllowed('https://rebinding.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class);
});

it('enforces the outbound allowlist for an automation call but not for a webhook target', function (): void {
    $guard = ($this->guardResolving)(['93.184.216.34']);

    expect(fn (): string => $guard->assertAllowed('https://hooks.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class)
        ->and($guard->assertAllowed('https://partner.example.com/inbox'))->toBe('93.184.216.34')
        ->and($guard->assertWebhookTargetAllowed('https://hooks.example.com/inbox'))->toBe('93.184.216.34');
});

it('applies the same allowlist to a redirect target as to the first call', function (): void {
    $guard = ($this->guardResolving)(['93.184.216.34']);

    expect(fn (): string => $guard->assertRedirectAllowed('https://hooks.example.com/inbox'))
        ->toThrow(SsrfBlockedException::class);
});

it('keeps a host that a trailing dot or a capital letter disguised on the allowlist check', function (): void {
    $guard = ($this->guardResolving)(['93.184.216.34']);

    expect($guard->assertAllowed('https://PARTNER.example.com./inbox'))->toBe('93.184.216.34');
});

it('reports the rejected target and the resolved address as structured data for the operator log', function (): void {
    $guard = ($this->guardResolving)(['10.1.2.3']);

    try {
        $guard->assertWebhookTargetAllowed('https://rebinding.example.com/inbox');
    } catch (SsrfBlockedException $exception) {
        expect($exception->ip)->toBe('10.1.2.3')
            ->and($exception->url)->toBe('https://rebinding.example.com/inbox')
            ->and($exception->reason)->not->toBe('');

        return;
    }

    $this->fail('the guard let an internal target through');
});

it('pins the connection to the validated ip with the port of the target', function (string $url, string $entry): void {
    expect((new SsrfGuard)->resolveEntryFor($url, '93.184.216.34'))->toBe($entry);
})->with([
    'https default port' => ['https://hooks.example.com/inbox', 'hooks.example.com:443:93.184.216.34'],
    'http default port' => ['http://hooks.example.com/inbox', 'hooks.example.com:80:93.184.216.34'],
    'explicit port' => ['https://hooks.example.com:8443/inbox', 'hooks.example.com:8443:93.184.216.34'],
]);
