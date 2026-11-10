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

    $this->resolverCalls = 0;

    /** @var callable(list<string>, array<string, list<string>>):SsrfGuard */
    $this->guardAllowing = function (array $allowedHosts, array $resolveMap = []): SsrfGuard {
        Config::set('automation.http.allowed_hosts', $allowedHosts);

        return new SsrfGuard(function (string $host) use ($resolveMap): array {
            $this->resolverCalls++;

            return $resolveMap[$host] ?? [$host];
        });
    };
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
    'benchmarking network' => ['198.18.0.1'],
    'benchmarking network upper half' => ['198.19.255.254'],
    'ietf protocol assignments' => ['192.0.0.8'],
    'six to four relay anycast' => ['192.88.99.1'],
    'reserved class e' => ['240.0.0.1'],
    'ipv4 multicast' => ['224.0.0.1'],
    'ipv4 administratively scoped multicast' => ['239.255.255.250'],
    'local use nat64 prefix' => ['64:ff9b:1::a00:5'],
    'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
    'ipv6 multicast' => ['ff02::1'],
    'ipv6 site scoped multicast' => ['ff05::1:3'],
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

it('blocks an internal address on the outbound path even when the allowlist names it', function (string $urlHost, string $allowlistEntry): void {
    $guard = ($this->guardAllowing)([$allowlistEntry]);

    expect(fn (): string => $guard->assertAllowed("http://{$urlHost}/path"))
        ->toThrow(SsrfBlockedException::class);
})->with([
    'loopback dotted' => ['127.0.0.1', '127.0.0.1'],
    'rfc1918 10/8' => ['10.0.0.1', '10.0.0.1'],
    'rfc1918 192.168/16' => ['192.168.1.10', '192.168.1.10'],
    'rfc1918 172.16/12' => ['172.16.5.4', '172.16.5.4'],
    'cloud metadata link-local' => ['169.254.169.254', '169.254.169.254'],
    'decimal-encoded loopback' => ['2130706433', '2130706433'],
    'hex-encoded loopback' => ['0x7f000001', '0x7f000001'],
    'octal-encoded loopback' => ['0177.0.0.1', '0177.0.0.1'],
    'ipv6 loopback' => ['[::1]', '::1'],
    'ipv6-mapped loopback' => ['[::ffff:127.0.0.1]', '::ffff:127.0.0.1'],
]);

it('blocks an allowlisted outbound host that rebinds to a private ip at runtime', function (): void {
    $guard = ($this->guardAllowing)(['api.allowed.test'], ['api.allowed.test' => ['10.0.0.7']]);

    expect(fn (): string => $guard->assertAllowed('https://api.allowed.test/hook'))
        ->toThrow(SsrfBlockedException::class);
});

it('blocks a public ip literal that the outbound allowlist does not name', function (): void {
    $guard = ($this->guardAllowing)(['api.allowed.test']);

    expect(fn (): string => $guard->assertAllowed('http://93.184.216.34/path'))
        ->toThrow(SsrfBlockedException::class);
});

it('refuses an outbound address that is malformed or names no host', function (string $url): void {
    $guard = ($this->guardAllowing)(['api.allowed.test']);

    expect(fn (): string => $guard->assertAllowed($url))
        ->toThrow(SsrfBlockedException::class);
})->with([
    'malformed url' => ['http://'],
    'empty host' => ['http://./path'],
]);

it('blocks a redirect target that resolves to an internal ip', function (): void {
    $guard = ($this->guardAllowing)(['api.allowed.test'], ['api.allowed.test' => ['93.184.216.34']]);

    expect(fn (): string => $guard->assertRedirectAllowed('http://169.254.169.254/latest/meta-data'))
        ->toThrow(SsrfBlockedException::class);
});

it('still checks the address of an allowlisted redirect host and names the reason it refuses', function (array $resolvedIps, string $reason): void {
    $guard = ($this->guardAllowing)(['redirect.allowed.test'], ['redirect.allowed.test' => $resolvedIps]);

    expect(fn (): string => $guard->assertRedirectAllowed('https://redirect.allowed.test/next'))
        ->toThrow(SsrfBlockedException::class, $reason);
})->with([
    'points at the metadata service' => [['169.254.169.254'], 'der Rechnername zeigt auf eine private oder reservierte IP-Adresse'],
    'resolves to nothing' => [[], 'der Rechnername ließ sich zu keiner IP-Adresse auflösen'],
]);

it('answers a validated redirect target with the ip it resolved', function (): void {
    $guard = ($this->guardAllowing)(['redirect.allowed.test'], ['redirect.allowed.test' => ['198.51.100.7']]);

    expect($guard->assertRedirectAllowed('https://redirect.allowed.test/next'))->toBe('198.51.100.7');
});

it('lets an allowlisted host pass the check without resolving it', function (): void {
    $guard = ($this->guardAllowing)(['api.allowed.test']);

    $guard->assertAllowedWithoutResolving('https://api.allowed.test/hook');

    expect($this->resolverCalls)->toBe(0);
});

it('normalises a host in upper case with a trailing dot onto the allowlist without resolving it', function (): void {
    $guard = ($this->guardAllowing)(['api.allowed.test']);

    expect(fn () => $guard->assertAllowedWithoutResolving('https://API.Allowed.Test./hook'))
        ->not->toThrow(SsrfBlockedException::class);

    expect($this->resolverCalls)->toBe(0);
});

it('normalises a blocked ip literal with a trailing dot and rejects it without resolving', function (): void {
    $guard = ($this->guardAllowing)(['169.254.169.254']);
    $url = 'http://169.254.169.254./latest/meta-data';

    expect(fn () => $guard->assertAllowedWithoutResolving($url))
        ->toThrow(function (SsrfBlockedException $exception) use ($url): void {
            expect($exception->reason)->toBe('der Rechnername zeigt auf eine private oder reservierte IP-Adresse')
                ->and($exception->url)->toBe($url)
                ->and($exception->ip)->toBe('169.254.169.254');
        });

    expect($this->resolverCalls)->toBe(0);
});

it('lets an allowlisted public ip literal pass without resolving it', function (): void {
    $guard = ($this->guardAllowing)(['93.184.216.34']);

    $guard->assertAllowedWithoutResolving('http://93.184.216.34/path');

    expect($this->resolverCalls)->toBe(0);
});

it('admits an allowlisted host by name without resolving while the resolved check still catches its private ip', function (): void {
    $guard = ($this->guardAllowing)(['api.allowed.test'], ['api.allowed.test' => ['10.0.0.7']]);

    $guard->assertAllowedWithoutResolving('https://api.allowed.test/hook');

    expect($this->resolverCalls)->toBe(0);

    expect(fn (): string => $guard->assertAllowed('https://api.allowed.test/hook'))
        ->toThrow(SsrfBlockedException::class, 'der Rechnername zeigt auf eine private oder reservierte IP-Adresse');

    expect($this->resolverCalls)->toBe(1);
});

it('rejects a target breaking a rule that needs no resolver with its reason and never resolves it', function (string $url, string $reason): void {
    $guard = ($this->guardAllowing)(['api.allowed.test', 'evil.internal']);

    expect(fn () => $guard->assertAllowedWithoutResolving($url))
        ->toThrow(function (SsrfBlockedException $exception) use ($url, $reason): void {
            expect($exception->reason)->toBe($reason)
                ->and($exception->url)->toBe($url)
                ->and($exception->ip)->toBeNull();
        });

    expect($this->resolverCalls)->toBe(0);
})->with([
    'file scheme' => ['file://api.allowed.test/etc/passwd', 'nur die Protokolle http und https sind zulässig'],
    'ftp scheme' => ['ftp://api.allowed.test/secret', 'nur die Protokolle http und https sind zulässig'],
    'user and password' => ['http://user:pass@api.allowed.test/', 'Anmeldedaten in der Adresse sind nicht zulässig'],
    'userinfo only' => ['http://api.allowed.test@evil.internal/', 'Anmeldedaten in der Adresse sind nicht zulässig'],
    'malformed url' => ['http://', 'die Adresse ist fehlerhaft aufgebaut'],
    'empty host' => ['http://./path', 'die Adresse nennt keinen Rechnernamen'],
    'host not on the allowlist' => ['https://evil.example.test/hook', 'der Rechnername steht nicht auf der Liste erlaubter Ziele'],
]);

it('rejects a public host by name with an empty allowlist and never resolves it', function (): void {
    $guard = ($this->guardAllowing)([]);

    expect(fn () => $guard->assertAllowedWithoutResolving('https://api.allowed.test/hook'))
        ->toThrow(SsrfBlockedException::class, 'der Rechnername steht nicht auf der Liste erlaubter Ziele');

    expect($this->resolverCalls)->toBe(0);
});

it('rejects an allowlisted ip literal of a blocked address without resolving it', function (string $urlHost, string $allowlistEntry, string $blockedIp): void {
    $guard = ($this->guardAllowing)([$allowlistEntry]);
    $url = "http://{$urlHost}/latest/meta-data";

    expect(fn () => $guard->assertAllowedWithoutResolving($url))
        ->toThrow(function (SsrfBlockedException $exception) use ($url, $blockedIp): void {
            expect($exception->reason)->toBe('der Rechnername zeigt auf eine private oder reservierte IP-Adresse')
                ->and($exception->url)->toBe($url)
                ->and($exception->ip)->toBe($blockedIp);
        });

    expect($this->resolverCalls)->toBe(0);
})->with([
    'cloud metadata link-local' => ['169.254.169.254', '169.254.169.254', '169.254.169.254'],
    'rfc1918 10/8' => ['10.0.0.1', '10.0.0.1', '10.0.0.1'],
    'decimal-encoded loopback' => ['2130706433', '2130706433', '127.0.0.1'],
    'hex-encoded loopback' => ['0x7f000001', '0x7f000001', '127.0.0.1'],
    'ipv6 loopback' => ['[::1]', '::1', '::1'],
    'ipv6 loopback in full form' => ['[0:0:0:0:0:0:0:1]', '0:0:0:0:0:0:0:1', '0:0:0:0:0:0:0:1'],
    'ipv6-mapped loopback' => ['[::ffff:127.0.0.1]', '::ffff:127.0.0.1', '::ffff:127.0.0.1'],
]);

it('rejects a blocked ip literal that is not on the allowlist by the allowlist rule first', function (): void {
    $guard = ($this->guardAllowing)(['api.allowed.test']);

    expect(fn () => $guard->assertAllowedWithoutResolving('http://169.254.169.254/latest/meta-data'))
        ->toThrow(function (SsrfBlockedException $exception): void {
            expect($exception->reason)->toBe('der Rechnername steht nicht auf der Liste erlaubter Ziele')
                ->and($exception->ip)->toBeNull();
        });

    expect($this->resolverCalls)->toBe(0);
});

it('keeps the reason and the ip of a webhook target on a blocked ip literal with an empty allowlist and never resolves it', function (): void {
    $guard = ($this->guardAllowing)([]);

    expect(fn () => $guard->assertWebhookTargetAllowed('https://169.254.169.254/'))
        ->toThrow(function (SsrfBlockedException $exception): void {
            expect($exception->reason)->toBe('der Rechnername zeigt auf eine private oder reservierte IP-Adresse')
                ->and($exception->url)->toBe('https://169.254.169.254/')
                ->and($exception->ip)->toBe('169.254.169.254');
        });

    expect($this->resolverCalls)->toBe(0);
});
