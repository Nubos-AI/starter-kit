<?php

declare(strict_types=1);

use App\DTOs\Mail\SystemMailData;
use App\DTOs\Users\ChangeUserPasswordData;
use App\DTOs\Users\IssuedUserTokenData;
use App\DTOs\Users\SealedUserTokenData;
use App\DTOs\Users\SealedUserValueData;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Exceptions\Temporal\SealBindingMismatchException;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->encrypter = new Encrypter(str_repeat('s', 32), 'aes-256-cbc');
});

it('opens a sealed value only for the tenant and user it was sealed for', function (): void {
    $value = SealedUserValueData::sealed($this->encrypter, 'tenant-a', 'user-1', 'neu@example.test');
    $token = SealedUserTokenData::sealed($this->encrypter, 'tenant-a', 'user-1', new IssuedUserTokenData('token-secret', CarbonImmutable::parse('2026-01-05T09:00:00Z')));
    $password = ChangeUserPasswordData::sealed($this->encrypter, 'tenant-a', 'user-1', ['password' => 'Geheim-123']);
    $mail = SystemMailData::sealed($this->encrypter, 'tenant-a', 'delivery-1', 'App\\Mail\\SomeMail', 'serialized-envelope');
    $delivery = WebhookDeliveryData::sealed($this->encrypter, 'subscription-1', 'tenant-a', 'event-1', 'record.created', 3, '{"email":"kunde@example.test"}');

    expect($value->unsealedValue($this->encrypter))->toBe('neu@example.test')
        ->and($token->unsealedToken($this->encrypter))->toBe('token-secret')
        ->and($password->unsealedInput($this->encrypter))->toBe(['password' => 'Geheim-123'])
        ->and($mail->unsealedEnvelope($this->encrypter))->toBe('serialized-envelope')
        ->and($delivery->unsealedBody($this->encrypter))->toBe('{"email":"kunde@example.test"}')
        ->and(json_encode($delivery->toTemporalPayload()))->not->toContain('kunde@example.test')
        ->and(fn () => (new SealedUserValueData('tenant-b', 'user-1', $value->sealedValue))->unsealedValue($this->encrypter))->toThrow(SealBindingMismatchException::class)
        ->and(fn () => (new SealedUserValueData('tenant-a', 'user-2', $value->sealedValue))->unsealedValue($this->encrypter))->toThrow(SealBindingMismatchException::class)
        ->and(fn () => (new SealedUserTokenData('tenant-a', 'user-2', $token->sealedToken, $token->expiresAt))->unsealedToken($this->encrypter))->toThrow(SealBindingMismatchException::class)
        ->and(fn () => (new ChangeUserPasswordData('tenant-b', 'user-1', $password->sealedInput))->unsealedInput($this->encrypter))->toThrow(SealBindingMismatchException::class)
        ->and(fn () => (new SystemMailData('tenant-b', 'delivery-1', $mail->mailClass, $mail->sealedEnvelope))->unsealedEnvelope($this->encrypter))->toThrow(SealBindingMismatchException::class)
        ->and(fn () => (new WebhookDeliveryData('subscription-2', 'tenant-a', 'event-1', 'record.created', 3, $delivery->sealedBody))->unsealedBody($this->encrypter))->toThrow(SealBindingMismatchException::class);
});
