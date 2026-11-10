<?php

declare(strict_types=1);

use App\Actions\Notifications\ArchiveInboxAction;
use App\Actions\Notifications\DeleteInboxItemAction;
use App\Actions\Notifications\MarkInboxReadAction;
use App\Actions\Notifications\MarkInboxUnreadAction;
use App\Actions\Notifications\SnoozeInboxAction;
use App\Http\Controllers\Notifications\InboxesController;
use App\Models\NotificationInbox;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('inbox-tenant');
    $this->owner = AccessContext::user($this->tenant, [], 'inbox-owner');
    $this->stranger = AccessContext::user($this->tenant, [], 'inbox-stranger');

    $this->row = ModelStub::make(NotificationInbox::class, [
        'id' => ModelStub::ulid('inbox-row'),
        'tenant_id' => $this->tenant->getKey(),
        'user_id' => $this->owner->getKey(),
        'type' => 'record.assigned',
        'priority' => 0,
    ]);

    $this->touched = [];

    $this->controller = function (): InboxesController {
        $probe = $this;

        $markRead = new class($probe) extends MarkInboxReadAction
        {
            public function __construct(private object $probe) {}

            public function execute(NotificationInbox $inbox): NotificationInbox
            {
                $this->probe->touched[] = 'read';

                return $inbox;
            }
        };

        $markUnread = new class($probe) extends MarkInboxUnreadAction
        {
            public function __construct(private object $probe) {}

            public function execute(NotificationInbox $inbox): NotificationInbox
            {
                $this->probe->touched[] = 'unread';

                return $inbox;
            }
        };

        $snooze = new class($probe) extends SnoozeInboxAction
        {
            public function __construct(private object $probe) {}

            public function execute(NotificationInbox $inbox, array $input): NotificationInbox
            {
                $this->probe->touched[] = 'snooze';

                return $inbox;
            }
        };

        $archive = new class($probe) extends ArchiveInboxAction
        {
            public function __construct(private object $probe) {}

            public function execute(NotificationInbox $inbox): NotificationInbox
            {
                $this->probe->touched[] = 'archive';

                return $inbox;
            }
        };

        $delete = new class($probe) extends DeleteInboxItemAction
        {
            public function __construct(private object $probe) {}

            public function execute(NotificationInbox $inbox): void
            {
                $this->probe->touched[] = 'delete';
            }
        };

        return new InboxesController($markRead, $markUnread, $snooze, $archive, $delete);
    };

    $this->requestBy = static function (User $user, array $payload = []): Request {
        $request = Request::create('/inbox', 'POST', $payload);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses every inbox action on a row that belongs to another user', function (): void {
    $controller = ($this->controller)();
    $request = ($this->requestBy)($this->stranger);

    expect(fn (): JsonResponse => $controller->markRead($request, $this->row))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->markUnread($request, $this->row))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->snooze($request, $this->row))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->archive($request, $this->row))->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $controller->destroy($request, $this->row))->toThrow(AuthorizationException::class)
        ->and($this->touched)->toBe([]);
});

it('lets the owner of a row read, unread, snooze, archive and delete it', function (): void {
    $controller = ($this->controller)();
    $request = ($this->requestBy)($this->owner);

    $controller->markRead($request, $this->row);
    $controller->markUnread($request, $this->row);
    $controller->snooze($request, $this->row);
    $controller->archive($request, $this->row);
    $controller->destroy($request, $this->row);

    expect($this->touched)->toBe(['read', 'unread', 'snooze', 'archive', 'delete']);
});

it('exposes only the inbox surface and never a raw internal column', function (): void {
    $payload = ($this->controller)()->markRead(($this->requestBy)($this->owner), $this->row)->getData(true);

    expect(array_keys($payload))->toBe(['id', 'type', 'data', 'priority', 'readAt', 'snoozedUntil', 'archivedAt', 'createdAt']);
});

it('demands a moment to snooze until', function (): void {
    $action = new SnoozeInboxAction;

    expect(fn (): NotificationInbox => $action->execute($this->row, []))->toThrow(ValidationException::class)
        ->and(fn (): NotificationInbox => $action->execute($this->row, ['snoozed_until' => 'irgendwann']))->toThrow(ValidationException::class);
});

it('refuses an unknown inbox state instead of listing everything', function (): void {
    $controller = ($this->controller)();

    expect(fn (): JsonResponse => $controller->index(($this->requestBy)($this->owner, ['state' => 'everything'])))
        ->toThrow(ValidationException::class);
});

it('lists the inbox of the acting user alone and never that of another user', function (): void {
    $controller = ($this->controller)();

    $attempt = QueryShape::attemptedBy(fn (): JsonResponse => $controller->index(($this->requestBy)($this->owner, ['state' => 'unread'])));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('notification_inbox'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->owner->getKey()))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->stranger->getKey()))->toBeFalse()
        ->and($attempt?->isScopedToTenant('notification_inbox', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->sql)->toContain('"read_at" is null')
        ->toContain('"archived_at" is null');
});

it('counts only the unread and unarchived rows of the acting user', function (): void {
    $controller = ($this->controller)();

    $attempt = QueryShape::attemptedBy(fn (): JsonResponse => $controller->unreadCount(($this->requestBy)($this->owner)));

    expect($attempt?->sql)->toContain('count(*)')
        ->toContain('"read_at" is null')
        ->toContain('"archived_at" is null')
        ->and($attempt?->hasBinding((string) $this->owner->getKey()))->toBeTrue();
});
