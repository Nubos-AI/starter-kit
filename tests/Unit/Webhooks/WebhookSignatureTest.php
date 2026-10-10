<?php

declare(strict_types=1);

use App\Support\Webhooks\WebhookSignature;
use Carbon\CarbonImmutable;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->signer = new WebhookSignature;
    $this->body = '{"event":"record.created","id":"01JABCDEF"}';
    $this->secret = 'whsec_test_secret';
    $this->goldenHex = '09608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4';
    $this->goldenHeader = 't=1700000000,v1=09608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4';

    /** @var callable(string, int, ?string, ?int):bool */
    $this->verify = function (string $header, int $nowTimestamp, ?string $body = null, ?int $toleranceSeconds = null): bool {
        $now = CarbonImmutable::createFromTimestamp($nowTimestamp, 'UTC');

        if ($toleranceSeconds === null) {
            return $this->signer->verify($body ?? $this->body, $this->secret, $header, $now);
        }

        return $this->signer->verify($body ?? $this->body, $this->secret, $header, $now, $toleranceSeconds);
    };
});

it('signs the payload exactly as openssl does for hmac sha256 over the timestamp and the raw body', function (): void {
    expect($this->signer->sign($this->body, $this->secret, 1700000000))->toBe($this->goldenHeader);
});

it('changes the signature when only the timestamp changes so the timestamp sits inside the mac', function (): void {
    $first = $this->signer->sign($this->body, $this->secret, 1700000000);
    $second = $this->signer->sign($this->body, $this->secret, 1700000001);

    expect($first)->not->toBe($second)
        ->and(explode(',v1=', $first)[1])->not->toBe(explode(',v1=', $second)[1]);
});

it('emits the wire format and never leaks the secret into the header', function (): void {
    $header = $this->signer->sign($this->body, $this->secret, 1700000000);

    expect($header)->toMatch('/^t=\d+,v1=[0-9a-f]{64}$/')
        ->and($header)->not->toContain($this->secret);
});

it('accepts a hard coded valid header at a matching clock', function (): void {
    expect(($this->verify)($this->goldenHeader, 1700000000))->toBeTrue();
});

it('rejects a tampered body against an unchanged header', function (): void {
    expect(($this->verify)($this->goldenHeader, 1700000000, '{"event":"record.deleted","id":"01JABCDEF"}'))->toBeFalse();
});

it('rejects a signature with a single flipped character', function (): void {
    expect(($this->verify)('t=1700000000,v1=19608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4', 1700000000))->toBeFalse();
});

it('rejects a replayed signature whose timestamp was moved inside the window', function (): void {
    expect(($this->verify)('t=1700000001,v1='.$this->goldenHex, 1700000001))->toBeFalse();
});

it('accepts a timestamp on either boundary of the replay window', function (string $header, int $now): void {
    expect(($this->verify)($header, $now))->toBeTrue();
})->with([
    'past boundary t = now-300' => ['t=1699999700,v1=7dd769f48615c0b4b9ebb7644133ee7551c2b28ff7a5fe86b5f4b9d7667be957', 1700000000],
    'future boundary t = now+300' => ['t=1700000300,v1=3c1bc9712ec60af547168a37347df39b960ace08ced6e8ece12a2cd290fa0182', 1700000000],
]);

it('rejects a correctly signed payload one second outside the replay window', function (string $header, int $now): void {
    expect(($this->verify)($header, $now))->toBeFalse();
})->with([
    'past t = now-301' => ['t=1699999699,v1=300a8de534b90b7b898bf9cd6f0a4d7f2894c67efb627d2f3740efd9bcc85e00', 1700000000],
    'future t = now+301' => ['t=1700000301,v1=ba750f31e005c57cc08804bdcbf2e7404990fd25ace83b58ba3bb01a1470d3b3', 1700000000],
]);

it('honours a caller supplied tolerance on its boundary', function (string $header, int $now): void {
    expect(($this->verify)($header, $now, null, 60))->toBeTrue();
})->with([
    'past boundary t = now-60' => ['t=1699999940,v1=abbb4c6387cd148d8c041cb89dccc6ea83861b723b96d1adec393dc4de286e22', 1700000000],
    'future boundary t = now+60' => ['t=1700000060,v1=1cf837470a1f414d41009a75702889ada7a4000713c8b0fffd1680c0d8293371', 1700000000],
]);

it('honours a caller supplied tolerance one second outside it although the default window would admit it', function (string $header, int $now): void {
    expect(($this->verify)($header, $now, null, 60))->toBeFalse();
})->with([
    'past t = now-61' => ['t=1699999939,v1=33a83c57f0c42c47f335e6cd8f2dfbd2c0c05e31cdd597b9daa830f9b12354c5', 1700000000],
    'future t = now+61' => ['t=1700000061,v1=21586930cae90704e5a5ad18926cf1d80c0119f0a754376c67b18775562bbd00', 1700000000],
]);

it('carries a correct mac in each custom window vector so the verdict comes from the clock alone', function (string $header, int $now): void {
    expect(($this->verify)($header, $now, null, 3600))->toBeTrue();
})->with([
    'past t = now-60' => ['t=1699999940,v1=abbb4c6387cd148d8c041cb89dccc6ea83861b723b96d1adec393dc4de286e22', 1700000000],
    'past t = now-61' => ['t=1699999939,v1=33a83c57f0c42c47f335e6cd8f2dfbd2c0c05e31cdd597b9daa830f9b12354c5', 1700000000],
    'future t = now+60' => ['t=1700000060,v1=1cf837470a1f414d41009a75702889ada7a4000713c8b0fffd1680c0d8293371', 1700000000],
    'future t = now+61' => ['t=1700000061,v1=21586930cae90704e5a5ad18926cf1d80c0119f0a754376c67b18775562bbd00', 1700000000],
]);

it('answers false on a malformed header instead of raising', function (string $header): void {
    expect(($this->verify)($header, 1700000000))->toBeFalse();
})->with([
    'empty string' => [''],
    'unstructured garbage' => ['garbage'],
    'only t' => ['t=1700000000'],
    'only v1' => ['v1=09608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4'],
    'short non-hex v1' => ['v1=abc'],
    'non-numeric t' => ['t=abc,v1=09608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4'],
    'items without an equals sign' => ['foo,bar'],
    'empty t' => ['t=,v1=09608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4'],
]);

it('accepts a header that carries a wrong candidate before the correct one so a rotation stays unbroken', function (): void {
    $header = 't=1700000000,v1=19608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4,v1='.$this->goldenHex;

    expect(($this->verify)($header, 1700000000))->toBeTrue();
});

it('rejects a header that carries only wrong candidates', function (): void {
    $header = 't=1700000000,v1=19608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4,v1=29608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4';

    expect(($this->verify)($header, 1700000000))->toBeFalse();
});

it('ignores an unknown scheme key rather than failing the verification', function (): void {
    expect(($this->verify)('t=1700000000,v0=deadbeef,v1='.$this->goldenHex, 1700000000))->toBeTrue();
});

it('compares the signature with a constant time comparison', function (): void {
    expect(file_get_contents(base_path('app/Support/Webhooks/WebhookSignature.php')))->toContain('hash_equals(');
});
