<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reminders;

use App\Actions\Reminders\BulkDeleteReminderTasksAction;
use App\Actions\Reminders\CompleteReminderTaskAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Actions\Reminders\DeleteReminderTaskAction;
use App\Actions\Reminders\UpdateReminderTaskAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\ReminderTaskResource;
use App\Models\CustomRecord;
use App\Models\ReminderTask;
use App\Support\Reminders\ReminderAuthority;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ReminderTasksController extends Controller
{
    use RespondsWithValidationErrors;

    public function __construct(
        private readonly CreateReminderTaskAction $createReminderTaskAction,
        private readonly UpdateReminderTaskAction $updateReminderTaskAction,
        private readonly CompleteReminderTaskAction $completeReminderTaskAction,
        private readonly DeleteReminderTaskAction $deleteReminderTaskAction,
        private readonly BulkDeleteReminderTasksAction $bulkDeleteReminderTasks,
        private readonly ReminderAuthority $reminderAuthority,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->actingUser($request);

        try {
            $reminder = $this->createReminderTaskAction->execute($request->all());
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return $this->reminderResponse($request, $reminder, 201);
    }

    public function update(Request $request, ReminderTask $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        try {
            $updated = $this->updateReminderTaskAction->execute($reminder, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return $this->reminderResponse($request, $updated);
    }

    public function complete(Request $request, ReminderTask $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        $completed = $this->completeReminderTaskAction->execute($reminder);

        return $this->reminderResponse($request, $completed);
    }

    public function destroy(Request $request, ReminderTask $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        $this->deleteReminderTaskAction->execute($reminder);

        return new JsonResponse(status: 204);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        try {
            $deleted = $this->bulkDeleteReminderTasks->execute($user, $request->all());
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(['deleted' => $deleted->count()]);
    }

    public function myOpen(Request $request): AnonymousResourceCollection
    {
        $user = $this->actingUser($request);

        $reminders = ReminderTask::query()
            ->with(['reminderType', 'record' => fn ($query) => $query->withTrashed(), 'owner', 'assignee'])
            ->openForUser($user)
            ->get();

        return ReminderTaskResource::collection($reminders);
    }

    public function forRecord(Request $request, string $record): AnonymousResourceCollection
    {
        $user = $this->actingUser($request);
        $customRecord = $this->resolveRecord($record);

        if ($user->cannot('view', $customRecord)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.reminders.reminder_tasks_controller.you_may_not_view_this_record'));
        }

        $reminders = ReminderTask::query()
            ->with(['reminderType', 'record' => fn ($query) => $query->withTrashed(), 'owner', 'assignee'])
            ->where('record_id', $customRecord->getKey())
            ->orderBy('due_at')
            ->get();

        return ReminderTaskResource::collection($reminders);
    }

    private function resolveRecord(string $id): CustomRecord
    {
        return CustomRecord::query()
            ->with('objectType')
            ->whereKey($id)
            ->firstOrFail();
    }

    private function authorizeReminder(Request $request, ReminderTask $reminder): void
    {
        $user = $this->actingUser($request);

        if (!$this->reminderAuthority->manages($user, $reminder)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.reminders.reminder_tasks_controller.you_do_not_have_permission_for_this_reminder'));
        }
    }

    private function reminderResponse(Request $request, ReminderTask $reminder, int $status = 200): JsonResponse
    {
        return new JsonResponse(
            ['data' => ReminderTaskResource::make($reminder)->resolve($request)],
            $status,
        );
    }
}
