<?php

declare(strict_types=1);

use App\Contracts\Mail\DeliverSystemMailWorkflowInterface;
use App\Contracts\Mail\SystemMailDispatcherInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Mail\SystemMailData;
use App\DTOs\Temporal\ProcessStartData;
use App\Notifications\Users\EmailAddressChangedNotification;
use App\Support\Mail\TemporalSystemMailDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Notification;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    Mockery::close();
});

it('hands a notification mail to a temporal workflow and never opens smtp in the calling process', function (): void {
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldNotReceive('send', 'raw', 'html', 'plain', 'to');
    app()->instance('mailer', $mailer);
    app()->instance(Mailer::class, $mailer);
    app()->instance(MailFactory::class, Mockery::mock(MailFactory::class)->shouldNotReceive('mailer')->getMock());

    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('mail.system_delivery.max_envelope_bytes')->andReturn(262144);

    $encrypter = Mockery::mock(Encrypter::class);
    $encrypter->shouldReceive('encryptString')->andReturnUsing(fn (string $plain): string => 'sealed:'.strlen($plain));

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('getLocale')->andReturn('de');

    $started = [];
    $processes = Mockery::mock(ProcessStarterInterface::class);
    $processes->shouldReceive('start')->once()->andReturnUsing(function (ProcessStartData $process) use (&$started): bool {
        $started[] = $process;

        return true;
    });

    app()->instance(SystemMailDispatcherInterface::class, new TemporalSystemMailDispatcher($processes, $encrypter, $config, $translator));

    Notification::route('mail', 'previous@example.test')->notify(new EmailAddressChangedNotification);

    $mail = $started[0]->arguments[0];

    expect($started)->toHaveCount(1)
        ->and($started[0]->workflow)->toBe(DeliverSystemMailWorkflowInterface::class)
        ->and($started[0]->workflowId)->toStartWith('system-mail:system:')
        ->and($mail)->toBeInstanceOf(SystemMailData::class)
        ->and($mail->sealedEnvelope)->toStartWith('sealed:')
        ->and($mail->mailClass)->toBe(EmailAddressChangedNotification::class)
        ->and(serialize($mail))->not->toContain('previous@example.test');
});
