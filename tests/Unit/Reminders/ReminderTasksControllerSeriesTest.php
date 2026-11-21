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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('reminder-series-tenant');
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'reminder-series-user'));
    $this->reminder = ModelStub::make(ReminderTask::class, ['id' => ModelStub::ulid('reminder-series-task'), 'tenant_id' => (string) $this->tenant->getKey(), 'subject' => 'Call back']);
    $this->delete = Mockery::mock(DeleteReminderTaskAction::class);
    $this->authority = Mockery::mock(ReminderAuthority::class);
    $this->controller = new ReminderTasksController(
        Mockery::mock(CreateReminderTaskAction::class),
        Mockery::mock(UpdateReminderTaskAction::class),
        Mockery::mock(CompleteReminderTaskAction::class),
        $this->delete,
        Mockery::mock(BulkDeleteReminderTasksAction::class),
        $this->authority,
        Mockery::mock(SourceLinkCatalog::class),
    );
    $this->request = function (array $payload): Request {
        $request = Request::create('/reminders/probe', 'DELETE', $payload);
        $request->setUserResolver(fn (): User => $this->user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('hands the chosen scope and occurrence of a series reminder on to the deletion', function (): void {
    $this->authority->shouldReceive('manages')->andReturnTrue();
    $this->delete->shouldReceive('execute')->once()->with($this->reminder, ['scope' => 'occurrence', 'occurrence_at' => '2026-10-27T09:00:00Z']);

    expect(($this->controller)->destroy(($this->request)(['scope' => 'occurrence', 'occurrence_at' => '2026-10-27T09:00:00Z', 'subject' => 'ignored']), $this->reminder)->getStatusCode())->toBe(204);
});

it('deletes nothing of a reminder the user does not manage', function (): void {
    $this->authority->shouldReceive('manages')->andReturnFalse();
    $this->delete->shouldNotReceive('execute');

    expect(fn () => ($this->controller)->destroy(($this->request)(['scope' => 'series']), $this->reminder))->toThrow(AuthorizationException::class);
});
