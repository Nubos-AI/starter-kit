<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\PurgeRecordAction;
use App\Actions\Engine\RestoreRecordAction;
use App\Enums\Engine\ObjectTypeCapability;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\RecordTitleResolver;
use App\Support\Trash\PurgeDeadline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TrashController extends Controller
{
    public function __construct(
        private readonly RestoreRecordAction $restoreRecordAction,
        private readonly PurgeRecordAction $purgeRecordAction,
        private readonly PurgeDeadline $purgeDeadline,
        private readonly ObjectTypeCapabilityGuard $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        $records = CustomRecord::onlyTrashed()
            ->with('objectType')
            ->orderByDesc('deleted_at')
            ->get()
            ->filter(fn (CustomRecord $record): bool => $this->keepsTrash($record)
                && $user->can('view', $record))
            ->map(fn (CustomRecord $record): array => $this->rowPayload($record, $user))
            ->values()
            ->all();

        return Inertia::render('trash/Index', [
            'records' => $records,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function restore(Request $request, string $id): RedirectResponse
    {
        $this->restoreRecordAction->execute($this->authorizedRecord($request, $id, 'restore'));

        return to_route('engine.trash.index');
    }

    /**
     * @throws Throwable
     */
    public function destroy(Request $request, string $id): HttpResponse
    {
        $this->purgeRecordAction->execute($this->authorizedRecord($request, $id, 'forceDelete'));

        return response()->noContent();
    }

    /**
     * @throws Throwable
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['string'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values($validated['ids']);

        foreach ($ids as $id) {
            $this->purgeRecordAction->execute($this->authorizedRecord($request, $id, 'forceDelete'));
        }

        return to_route('engine.trash.index');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizedRecord(Request $request, string $id, string $ability): CustomRecord
    {
        $user = $this->actingUser($request);
        $record = CustomRecord::onlyTrashed()->whereKey($id)->firstOrFail();

        $this->capabilities->assertSupports($record->objectType, ObjectTypeCapability::Trash);

        if ($user->cannot('view', $record) || $user->cannot($ability, $record)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.trash_controller.you_do_not_have_permission_for_this_deleted_record'));
        }

        return $record;
    }

    private function keepsTrash(CustomRecord $record): bool
    {
        return $this->capabilities->supports($record->objectType, ObjectTypeCapability::Trash);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(CustomRecord $record, User $user): array
    {
        return [
            'id' => (string) $record->getKey(),
            'objectTypeName' => $record->objectType->name,
            'objectTypeSlug' => $record->objectType->slug,
            'recordNumber' => $record->record_number,
            'title' => RecordTitleResolver::forRequest()->titleFor($user, $record),
            'deletionReason' => $record->deletion_reason,
            'deletedAt' => $record->deleted_at?->toISOString(),
            'purgeOn' => $this->purgeDeadline->for($record)?->toDateString(),
            'canRestore' => $user->can('restore', $record),
            'canDelete' => $user->can('forceDelete', $record),
        ];
    }
}
