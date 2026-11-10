<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\ReminderTask;
use App\Notifications\Abstracts\EngineNotification;
use App\Notifications\ExportCompletedNotification;
use App\Notifications\GroupedBulkNotification;
use App\Notifications\ImportCompletedNotification;
use App\Notifications\ReminderDueNotification;
use App\Notifications\RuleNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('notification-content-tenant');
    $this->recipient = AccessContext::user($this->tenant, ['timezone' => 'Europe/Berlin', 'current_team_id' => 'personal'], 'notification-content-user');
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "teams"') ? [['id' => 'personal']] : []);
    $this->genericTitle = __('i18n.backend.notifications.abstracts.engine_notification.notification');

    $this->reminder = fn (array $attributes = []): ReminderTask => ModelStub::make(ReminderTask::class, [
        'id' => ModelStub::ulid('notification-content-reminder'),
        'tenant_id' => $this->tenant->getKey(),
        'subject' => 'Rückruf Müller',
        'due_at' => '2026-11-09 08:00:00',
        'record_id' => null,
        ...$attributes,
    ]);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('notification-content-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Firmen',
        'slug' => 'companies',
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('names the reminder subject and its due time on the clock of the assignee in the inbox, the mail and the push message', function (): void {
    $notification = new ReminderDueNotification(($this->reminder)());

    $inbox = $notification->toInbox($this->recipient);
    $mail = $notification->toMail($this->recipient);
    $push = $notification->toWebPush($this->recipient)->toArray();

    expect($inbox['title'])->toBe(__('i18n.backend.notifications.reminder_due_notification.title', ['value1' => 'Rückruf Müller']))
        ->and($inbox['title'])->toContain('Rückruf Müller')
        ->and($inbox['body'])->toBe(__('i18n.backend.notifications.reminder_due_notification.due_at', ['value1' => '09.11.2026, 09:00']))
        ->and($mail->subject)->toBe($inbox['title'])
        ->and($mail->introLines)->toBe([$inbox['body']])
        ->and($push['title'])->toBe($inbox['title'])
        ->and($push['body'])->toBe($inbox['body']);
});

it('still tells the assignee that a reminder without a due date is due', function (): void {
    $inbox = (new ReminderDueNotification(($this->reminder)(['due_at' => null])))->toInbox($this->recipient);

    expect($inbox['body'])->toBe(__('i18n.backend.notifications.reminder_due_notification.due_now'));
});

it('links the mail to the target of the notification and leaves the link out when there is none', function (): void {
    $recordId = ModelStub::ulid('notification-content-record');
    $linked = (new ReminderDueNotification(($this->reminder)(['record_id' => $recordId])))->toMail($this->recipient);
    $unlinked = (new ReminderDueNotification(($this->reminder)()))->toMail($this->recipient);

    expect($linked->actionUrl)->toBe(route('engine.records.show', ['activeTeam' => 'personal', 'record' => $recordId]))
        ->and($linked->actionText)->toBe(__('i18n.backend.notifications.abstracts.engine_notification.open'))
        ->and($unlinked->actionUrl)->toBeNull();
});

it('gives every core notification a German title of its own and a body in the inbox, the mail and the push message', function (Closure $build): void {
    $notification = $build->call($this);

    $inbox = $notification->toInbox($this->recipient);
    $mail = $notification->toMail($this->recipient);
    $push = $notification->toWebPush($this->recipient)->toArray();

    expect($inbox['title'] ?? null)->toBeString()->not->toBe($this->genericTitle)
        ->and($inbox['body'] ?? null)->toBeString()->not->toBe('')
        ->and($mail->subject)->toBe($inbox['title'])
        ->and($push['title'])->toBe($inbox['title'])
        ->and($push['body'])->not->toBe('');
})->with([
    'a due reminder' => [fn (): EngineNotification => new ReminderDueNotification(($this->reminder)())],
    'a triggered rule' => [fn (): EngineNotification => new RuleNotification(
        ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('notification-content-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Großkunden ohne Kontakt']),
        ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('notification-content-rule-record'), 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-17']),
    )],
    'a grouped bulk change' => [fn (): EngineNotification => new GroupedBulkNotification(12, 'companies')],
    'a finished export' => [fn (): EngineNotification => new ExportCompletedNotification(ModelStub::make(ExportJob::class, [
        'id' => ModelStub::ulid('notification-content-export'),
        'tenant_id' => $this->tenant->getKey(),
        'status' => 'completed',
        'format' => 'csv',
        'row_count' => 40,
        'result_path' => null,
    ], ['objectType' => $this->objectType]))],
    'a finished import' => [fn (): EngineNotification => new ImportCompletedNotification(ModelStub::make(ImportJob::class, [
        'id' => ModelStub::ulid('notification-content-import'),
        'tenant_id' => $this->tenant->getKey(),
        'status' => 'completed',
        'original_filename' => 'firmen.csv',
        'created_count' => 3,
        'updated_count' => 1,
        'error_count' => 0,
        'error_report_path' => null,
    ], ['objectType' => $this->objectType]))],
]);

it('names the rule and the record number of the triggering record', function (): void {
    $inbox = (new RuleNotification(
        ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('notification-content-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Großkunden ohne Kontakt']),
        ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('notification-content-rule-record'), 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-17']),
    ))->toInbox($this->recipient);

    expect($inbox['title'])->toContain('Großkunden ohne Kontakt')
        ->and($inbox['body'])->toContain('F-17');
});

it('hands the push message plain text when a mail line carries markup', function (): void {
    $notification = new class extends EngineNotification
    {
        public function type(): string
        {
            return 'test.markup';
        }

        public function toInbox(mixed $notifiable): array
        {
            return ['title' => 'Fällig'];
        }

        public function toMail(mixed $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Fällig')->line(new HtmlString('<strong>Heute</strong> fällig'))->line('Bitte prüfen');
        }
    };

    expect($notification->toWebPush($this->recipient)->toArray()['body'])->toBe('Heute fällig Bitte prüfen');
});
