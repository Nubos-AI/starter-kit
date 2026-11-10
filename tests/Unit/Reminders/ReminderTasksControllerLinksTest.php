<?php

declare(strict_types=1);

use App\Actions\Reminders\BulkDeleteReminderTasksAction;
use App\Actions\Reminders\CompleteReminderTaskAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Actions\Reminders\DeleteReminderTaskAction;
use App\Actions\Reminders\UpdateReminderTaskAction;
use App\Http\Controllers\Reminders\ReminderTasksController;
use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Links\SourceLinkCatalog;
use App\Support\Reminders\ReminderAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('reminder-links-tenant');
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'reminder-links-user'));
    $this->firstId = ModelStub::ulid('reminder-links-first');
    $this->secondId = ModelStub::ulid('reminder-links-second');

    $this->rowFor = fn (string $id): array => [
        'id' => $id,
        'tenant_id' => (string) $this->tenant->getKey(),
        'owner_id' => (string) $this->user->getKey(),
        'creator_id' => (string) $this->user->getKey(),
        'assignee_id' => (string) $this->user->getKey(),
        'record_id' => null,
        'source_type' => 'email_message',
        'source_id' => ModelStub::ulid('reminder-links-mail'),
        'subject' => 'Call back',
        'due_at' => '2026-10-28 09:00:00',
        'done_at' => null,
    ];

    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "reminder_tasks"')
        ? [($this->rowFor)($this->firstId), ($this->rowFor)($this->secondId)]
        : []);

    $this->catalog = Mockery::mock(SourceLinkCatalog::class);

    $this->controller = new ReminderTasksController(
        Mockery::mock(CreateReminderTaskAction::class),
        Mockery::mock(UpdateReminderTaskAction::class),
        Mockery::mock(CompleteReminderTaskAction::class),
        Mockery::mock(DeleteReminderTaskAction::class),
        Mockery::mock(BulkDeleteReminderTasksAction::class),
        Mockery::mock(ReminderAuthority::class),
        $this->catalog,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Mockery::close();
    AccessContext::forgetTenant();
});

it('resolves the quick links of all open reminders in one go and hands each reminder only its own', function (): void {
    $link = ['key' => 'email_message', 'id' => ModelStub::ulid('reminder-links-mail'), 'label' => 'E-Mail: Rückruf', 'href' => '/mails/1'];

    $this->catalog->shouldReceive('linksFor')
        ->once()
        ->withArgs(fn (User $user, Collection $items): bool => $user === $this->user
            && $items->map(fn (ReminderTask $reminder): string => $reminder->id)->all() === [$this->firstId, $this->secondId])
        ->andReturn([$this->firstId => [$link], $this->secondId => []]);

    $request = Request::create('/reminders/my-open');
    $request->setUserResolver(fn (): User => $this->user);

    $payload = $this->controller->myOpen($request)->toResponse($request)->getData(true)['data'];

    expect($payload[0]['links'])->toBe([$link])
        ->and($payload[1]['links'])->toBe([]);
});
