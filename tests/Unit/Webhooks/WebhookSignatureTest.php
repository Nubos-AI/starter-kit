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
    $this->now = CarbonImmutable::createFromTimestamp(1700000000, 'UTC');
});

it('rejects a signature that does not match the body, the secret or the signed timestamp', function (string $header, string $body): void {
    expect($this->signer->verify($body, $this->secret, $header, $this->now))->toBeFalse();
})->with([
    'tampered body' => ['t=1700000000,v1=09608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4', '{"event":"record.deleted","id":"01JABCDEF"}'],
    'one flipped character' => ['t=1700000000,v1=19608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4', '{"event":"record.created","id":"01JABCDEF"}'],
    'timestamp moved after signing' => ['t=1700000001,v1=09608f698a8f96ce877f98f957f4472ecd2b8371d1f9bbfc9dc95b571022b0c4', '{"event":"record.created","id":"01JABCDEF"}'],
    'no signature at all' => ['t=1700000000', '{"event":"record.created","id":"01JABCDEF"}'],
]);

it('accepts a valid signature only inside the replay window', function (int $signedAt, bool $isAccepted): void {
    $header = $this->signer->sign($this->body, $this->secret, $signedAt);

    expect($this->signer->verify($this->body, $this->secret, $header, $this->now))->toBe($isAccepted);
})->with([
    'signed now' => [1700000000, true],
    'past boundary t = now-300' => [1699999700, true],
    'future boundary t = now+300' => [1700000300, true],
    'replayed t = now-301' => [1699999699, false],
    'future t = now+301' => [1700000301, false],
]);
