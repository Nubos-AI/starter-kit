<?php

declare(strict_types=1);

use App\Enums\Mail\SmtpFailureKind;
use App\Support\Mail\SmtpFailureClassifier;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('retries a mail only when the smtp connection was never established, because then nothing reached the server', function (Throwable $failure, SmtpFailureKind $kind, bool $isSafeToRetry): void {
    $classifier = new SmtpFailureClassifier;

    expect($classifier->classify($failure))->toBe($kind)
        ->and($classifier->isSafeToRetry($failure))->toBe($isSafeToRetry);
})->with([
    'connection timed out' => [new TransportException('Connection could not be established with host "mail.example.test:587": stream_socket_client(): Unable to connect to mail.example.test:587 (Connection timed out)'), SmtpFailureKind::NotConnected, true],
    'connection refused, wrapped' => [new RuntimeException('Mail delivery failed.', 0, new TransportException('Connection could not be established with host "127.0.0.1:25": stream_socket_client(): Unable to connect to 127.0.0.1:25 (Connection refused)')), SmtpFailureKind::NotConnected, true],
    'starttls handshake failed' => [new TransportException('Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed'), SmtpFailureKind::NotConnected, true],
    'refused after data' => [new UnexpectedResponseException('Expected response code "250" but got code "554", with message "554 5.7.1 Message rejected".', 554), SmtpFailureKind::Refused, false],
    'connection lost during the dialogue' => [new TransportException('Connection to "mail.example.test:587" has been closed unexpectedly.'), SmtpFailureKind::Interrupted, false],
    'read timed out after data' => [new TransportException('Connection to "mail.example.test:587" timed out.'), SmtpFailureKind::Interrupted, false],
]);

it('leaves a failure that never touched smtp unclassified', function (): void {
    expect((new SmtpFailureClassifier)->classify(new RuntimeException('Rendering failed.')))->toBeNull();
});
