<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\ArchiveInboxAction;
use App\Actions\Notifications\DeleteInboxItemAction;
use App\Actions\Notifications\MarkInboxReadAction;
use App\Actions\Notifications\MarkInboxUnreadAction;
use App\Actions\Notifications\SnoozeInboxAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\NotificationInbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxesController extends Controller
{
    private int $perPage = 25;

    public function __construct(
        private readonly MarkInboxReadAction $markRead,
        private readonly MarkInboxUnreadAction $markUnread,
        private readonly SnoozeInboxAction $snoozeInbox,
        private readonly ArchiveInboxAction $archiveInbox,
        private readonly DeleteInboxItemAction $deleteInboxItem,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        $validated = $request->validate([
            'state' => ['sometimes', 'string', 'in:messages,unread,snoozed,archived'],
        ]);

        $query = NotificationInbox::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at');

        match ($validated['state'] ?? null) {
            'messages' => $query->whereNull('archived_at'),
            'unread' => $query->whereNull('read_at')->whereNull('archived_at'),
            'snoozed' => $query->whereNotNull('snoozed_until'),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query,
        };

        $page = $query->paginate($this->perPage);

        return new JsonResponse([
            'data' => array_map($this->present(...), $page->items()),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function markRead(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $this->authorizeRow($request, $inbox);

        return new JsonResponse($this->present($this->markRead->execute($inbox)));
    }

    public function markUnread(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $this->authorizeRow($request, $inbox);

        return new JsonResponse($this->present($this->markUnread->execute($inbox)));
    }

    public function snooze(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $this->authorizeRow($request, $inbox);

        return new JsonResponse($this->present($this->snoozeInbox->execute($inbox, $request->all())));
    }

    public function archive(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $this->authorizeRow($request, $inbox);

        return new JsonResponse($this->present($this->archiveInbox->execute($inbox)));
    }

    public function destroy(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $this->authorizeRow($request, $inbox);

        $this->deleteInboxItem->execute($inbox);

        return new JsonResponse(['id' => $inbox->getKey()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        $count = NotificationInbox::query()
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->count();

        return new JsonResponse(['count' => $count]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotificationInbox $inbox): array
    {
        return [
            'id' => $inbox->getKey(),
            'type' => $inbox->type,
            'data' => $inbox->data,
            'priority' => $inbox->priority,
            'readAt' => $inbox->read_at?->toISOString(),
            'snoozedUntil' => $inbox->snoozed_until?->toISOString(),
            'archivedAt' => $inbox->archived_at?->toISOString(),
            'createdAt' => $inbox->created_at?->toISOString(),
        ];
    }

    private function authorizeRow(Request $request, NotificationInbox $inbox): void
    {
        if ($inbox->user_id !== (string) $this->actingUser($request)->getKey()) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.notifications.inboxes_controller.you_do_not_have_permission_for_this_notification'));
        }
    }
}
