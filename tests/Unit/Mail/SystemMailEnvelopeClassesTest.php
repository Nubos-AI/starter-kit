<?php

declare(strict_types=1);

use App\Actions\Mail\DeliverSystemMailAction;
use App\DTOs\Mail\SystemMailData;
use App\DTOs\Mail\SystemMailEnvelope;
use App\Exceptions\Mail\SystemMailEnvelopeRejectedException;
use App\Notifications\Users\EmailAddressChangedNotification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Encryption\Encrypter;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\MailChannel;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->encrypter = new Encrypter(str_repeat('m', 32), 'aes-256-cbc');

    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('mail.system_delivery.envelope_classes')->andReturn([
        'App\\',
        'Illuminate\\Notifications\\AnonymousNotifiable',
    ]);

    $this->mailChannel = Mockery::mock(MailChannel::class);

    $this->action = new DeliverSystemMailAction($this->encrypter, $this->mailChannel, Mockery::mock(Mailer::class), $config);

    /** @var callable(SystemMailEnvelope):SystemMailData */
    $this->sealedMail = fn (SystemMailEnvelope $envelope): SystemMailData => SystemMailData::sealed(
        $this->encrypter,
        null,
        'delivery-1',
        EmailAddressChangedNotification::class,
        serialize($envelope),
    );
});

afterEach(function (): void {
    Mockery::close();
});

it('opens only envelopes whose every object belongs to an allowed class and never instantiates any other', function (): void {
    $notifiable = (new AnonymousNotifiable)->route('mail', 'previous@example.test');

    $this->mailChannel->shouldReceive('send')->once();

    $this->action->execute(($this->sealedMail)(new SystemMailEnvelope('de', $notifiable, new EmailAddressChangedNotification)));

    $foreign = ($this->sealedMail)(new SystemMailEnvelope('de', new ArrayObject(['gadget']), new EmailAddressChangedNotification));

    expect(fn () => $this->action->execute($foreign))->toThrow(SystemMailEnvelopeRejectedException::class);
});
