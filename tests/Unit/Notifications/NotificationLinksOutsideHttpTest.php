<?php

declare(strict_types=1);

use App\Models\ApprovalProcess;
use App\Models\CustomRecord;
use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\ReminderTask;
use App\Notifications\ExportCompletedNotification;
use App\Notifications\ImportCompletedNotification;
use App\Notifications\ReminderDueNotification;
use App\Notifications\RuleNotification;
use App\Support\Approvals\ApprovalAnchorPresenter;
use App\Support\Teams\TeamSegment;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);

    $this->tenant = AccessContext::tenant('links-outside-http-tenant');
    $this->teamId = ModelStub::ulid('links-outside-http-team');
    $this->recipient = AccessContext::user($this->tenant, ['current_team_id' => $this->teamId], 'links-outside-http-recipient');
    $this->recordId = ModelStub::ulid('links-outside-http-record');
    $teamId = $this->teamId;
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "teams"') ? [['id' => $teamId]] : []);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('links-outside-http-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Firmen',
        'slug' => 'companies',
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('builds every notification link with the team of the recipient when no request has set a team segment', function (Closure $build, string $expectedPath, bool $isLinkedInMail): void {
    $notification = $build->call($this);

    $inbox = $notification->toInbox($this->recipient);
    $mail = $notification->toMail($this->recipient);
    $push = $notification->toWebPush($this->recipient)->toArray();

    expect($inbox['url'])->toEndWith(str_replace('{team}', $this->teamId, $expectedPath))
        ->and($mail->actionUrl)->toBe($isLinkedInMail ? $inbox['url'] : null)
        ->and($push['data']['url'] ?? null)->toBe($inbox['url']);
})->with([
    'a due reminder' => [
        fn (): ReminderDueNotification => new ReminderDueNotification(ModelStub::make(ReminderTask::class, [
            'id' => ModelStub::ulid('links-outside-http-reminder'),
            'tenant_id' => $this->tenant->getKey(),
            'subject' => 'Rückruf',
            'due_at' => '2026-11-09 08:00:00',
            'record_id' => $this->recordId,
        ])),
        '/{team}/records/'.ModelStub::ulid('links-outside-http-record'),
        true,
    ],
    'a triggered rule' => [
        fn (): RuleNotification => new RuleNotification(
            ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('links-outside-http-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Regel']),
            ModelStub::make(CustomRecord::class, ['id' => $this->recordId, 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-1']),
        ),
        '/{team}/records/'.ModelStub::ulid('links-outside-http-record'),
        true,
    ],
    'a finished export' => [
        fn (): ExportCompletedNotification => new ExportCompletedNotification(ModelStub::make(ExportJob::class, [
            'id' => ModelStub::ulid('links-outside-http-export'),
            'tenant_id' => $this->tenant->getKey(),
            'status' => 'completed',
            'format' => 'csv',
            'row_count' => 3,
            'result_path' => 'exports/file.csv',
        ], ['objectType' => $this->objectType])),
        '/{team}/engine/export/companies/download/'.ModelStub::ulid('links-outside-http-export'),
        false,
    ],
    'a finished import with an error report' => [
        fn (): ImportCompletedNotification => new ImportCompletedNotification(ModelStub::make(ImportJob::class, [
            'id' => ModelStub::ulid('links-outside-http-import'),
            'tenant_id' => $this->tenant->getKey(),
            'status' => 'completed',
            'original_filename' => 'firmen.csv',
            'created_count' => 1,
            'updated_count' => 0,
            'error_count' => 1,
            'error_report_path' => 'imports/errors.csv',
        ], ['objectType' => $this->objectType])),
        '/{team}/engine/import/companies/error-report/'.ModelStub::ulid('links-outside-http-import'),
        false,
    ],
]);

it('links an approval notification to the record inside the team of the recipient', function (): void {
    $process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('links-outside-http-approval'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->recordId,
    ]);

    expect(app(ApprovalAnchorPresenter::class)->targetUrl($process, $this->recipient))
        ->toEndWith("/{$this->teamId}/records/{$this->recordId}");
});

it('uses the team of the recipient even while a request of another user has set its own team segment', function (): void {
    URL::defaults([TeamSegment::key() => 'acting-users-team']);
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'links-outside-http-actor'));

    $inbox = (new RuleNotification(
        ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('links-outside-http-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Regel']),
        ModelStub::make(CustomRecord::class, ['id' => $this->recordId, 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-1']),
    ))->toInbox($this->recipient);

    expect($inbox['url'])->toEndWith("/{$this->teamId}/records/{$this->recordId}");
});

it('falls back to a team the recipient belongs to when no current team is set', function (): void {
    $memberTeam = ModelStub::ulid('links-outside-http-member-team');
    $recipient = AccessContext::user($this->tenant, ['current_team_id' => null], 'links-outside-http-teamless');
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "teams"') ? [['id' => $memberTeam]] : [],
    );

    try {
        $inbox = (new RuleNotification(
            ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('links-outside-http-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Regel']),
            ModelStub::make(CustomRecord::class, ['id' => $this->recordId, 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-1']),
        ))->toInbox($recipient);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($inbox['url'])->toEndWith("/{$memberTeam}/records/{$this->recordId}")
        ->and($connection->queries[0]['sql'])->toContain('"teams"."deleted_at" is null')
        ->and($connection->queries[0]['bindings'])->toContain($recipient->getKey())
        ->and($connection->queries[0]['bindings'])->toContain($this->tenant->getKey());
});

it('leaves the link out and logs the recipient when the recipient belongs to no team at all', function (): void {
    Log::spy();
    $recipient = AccessContext::user($this->tenant, ['current_team_id' => null], 'links-outside-http-no-team');
    StaticQueryConnection::install(static fn (): array => []);

    try {
        $notification = new RuleNotification(
            ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('links-outside-http-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Regel']),
            ModelStub::make(CustomRecord::class, ['id' => $this->recordId, 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-1']),
        );
        $inbox = $notification->toInbox($recipient);
        $mail = $notification->toMail($recipient);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($inbox['url'])->toBeNull()
        ->and($mail->actionUrl)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $context['recipient_id'] === $recipient->getKey()
        && $context['route'] === 'engine.records.show');
});

it('does not link into the current team once the recipient is no longer a member of it', function (): void {
    $remainingTeam = ModelStub::ulid('links-outside-http-remaining-team');
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "teams"') ? [['id' => $remainingTeam]] : [],
    );

    try {
        $inbox = (new RuleNotification(
            ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('links-outside-http-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Regel']),
            ModelStub::make(CustomRecord::class, ['id' => $this->recordId, 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-1']),
        ))->toInbox($this->recipient);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($inbox['url'])->toEndWith("/{$remainingTeam}/records/{$this->recordId}")
        ->and($connection->queries)->toHaveCount(1)
        ->and($connection->queries[0]['sql'])->toContain('inner join "team_user"')
        ->and($connection->queries[0]['sql'])->toContain('"teams"."deleted_at" is null')
        ->and($connection->queries[0]['bindings'])->toBe([$this->recipient->getKey(), $this->tenant->getKey(), $this->teamId]);
});

it('takes the request team of the acting recipient only once the team context middleware has verified the membership', function (): void {
    URL::defaults([TeamSegment::key() => 'request-team']);
    AccessContext::actAs($this->recipient);
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "teams"') ? [['id' => ModelStub::ulid('links-outside-http-team')]] : [],
    );

    try {
        $inbox = (new RuleNotification(
            ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('links-outside-http-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Regel']),
            ModelStub::make(CustomRecord::class, ['id' => $this->recordId, 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-1']),
        ))->toInbox($this->recipient);

        Context::addHidden('team_id', $this->teamId);

        $verifiedInbox = (new RuleNotification(
            ModelStub::make(NotificationRule::class, ['id' => ModelStub::ulid('links-outside-http-rule'), 'tenant_id' => $this->tenant->getKey(), 'name' => 'Regel']),
            ModelStub::make(CustomRecord::class, ['id' => $this->recordId, 'tenant_id' => $this->tenant->getKey(), 'record_number' => 'F-1']),
        ))->toInbox($this->recipient);
    } finally {
        StaticQueryConnection::uninstall();
        Context::forgetHidden('team_id');
    }

    expect($inbox['url'])->toEndWith("/{$this->teamId}/records/{$this->recordId}")
        ->and($verifiedInbox['url'])->toEndWith("/request-team/records/{$this->recordId}")
        ->and($connection->queries)->toHaveCount(1);
});
