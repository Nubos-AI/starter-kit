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

it('accepts a timestamp on either boundary of the replay window', function (int $signedAt): void {
    expect(($this->verify)($this->signer->sign($this->body, $this->secret, $signedAt), 1700000000))->toBeTrue();
})->with([
    'past boundary t = now-300' => [1699999700],
    'future boundary t = now+300' => [1700000300],
]);

it('rejects a correctly signed payload one second outside the replay window', function (int $signedAt): void {
    expect(($this->verify)($this->signer->sign($this->body, $this->secret, $signedAt), 1700000000))->toBeFalse();
})->with([
    'past t = now-301' => [1699999699],
    'future t = now+301' => [1700000301],
]);

it('honours a caller supplied tolerance on its boundary', function (int $signedAt): void {
    expect(($this->verify)($this->signer->sign($this->body, $this->secret, $signedAt), 1700000000, null, 60))->toBeTrue();
})->with([
    'past boundary t = now-60' => [1699999940],
    'future boundary t = now+60' => [1700000060],
]);

it('honours a caller supplied tolerance one second outside it although the default window would admit it', function (int $signedAt): void {
    $header = $this->signer->sign($this->body, $this->secret, $signedAt);

    expect(($this->verify)($header, 1700000000, null, 60))->toBeFalse()
        ->and(($this->verify)($header, 1700000000))->toBeTrue();
})->with([
    'past t = now-61' => [1699999939],
    'future t = now+61' => [1700000061],
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
