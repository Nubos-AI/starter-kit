<?php

declare(strict_types=1);

namespace App\Http\Controllers\Watchers;

use App\Actions\Records\SyncRecordWatchersAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Enums\Watchers\WatcherSource;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\WatcherResource;
use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Models\User;
use App\Support\Watchers\WatcherAutoSubscriber;
use App\Support\Watchers\WatcherEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WatchersController extends Controller
{
    public function __construct(
        private readonly WatcherAutoSubscriber $watcherAutoSubscriber,
        private readonly SyncRecordWatchersAction $syncRecordWatchers,
        private readonly WatcherEligibility $watcherEligibility,
    ) {}

    public function index(Request $request, string $record): AnonymousResourceCollection
    {
        $user = $this->actingUser($request);
        $customRecord = $this->resolveRecord($record);
        $this->authorizeView($user, $customRecord);

        $watchers = RecordWatcher::query()
            ->where('record_id', $customRecord->getKey())
            ->with('user')
            ->get();

        return WatcherResource::collection($watchers);
    }

    public function sync(Request $request, string $record): AnonymousResourceCollection
    {
        $actor = $this->actingUser($request);
        $customRecord = $this->resolveRecord($record);
        $this->authorizeView($actor, $customRecord);
        $this->authorizeManage($actor, $customRecord);

        $this->syncRecordWatchers->execute($customRecord, $request->all());

        $watchers = RecordWatcher::query()
            ->where('record_id', $customRecord->getKey())
            ->with('user')
            ->get();

        return WatcherResource::collection($watchers);
    }

    public function follow(Request $request, string $record): JsonResponse
    {
        $user = $this->actingUser($request);
        $customRecord = $this->resolveRecord($record);
        $this->authorizeView($user, $customRecord);

        $watcher = $this->watcherAutoSubscriber->subscribe(
            (string) $customRecord->getKey(),
            (string) $user->getKey(),
            WatcherSource::Manual,
        );

        return $this->watcherResponse($request, $watcher);
    }

    public function unfollow(Request $request, string $record): JsonResponse
    {
        $user = $this->actingUser($request);
        $customRecord = $this->resolveRecord($record);

        $this->watcherAutoSubscriber->unsubscribe(
            (string) $customRecord->getKey(),
            (string) $user->getKey(),
        );

        return new JsonResponse(status: 204);
    }

    public function addWatcher(Request $request, string $record, string $user): JsonResponse
    {
        $actor = $this->actingUser($request);
        $customRecord = $this->resolveRecord($record);
        $this->authorizeView($actor, $customRecord);
        $this->authorizeManage($actor, $customRecord);

        $target = User::query()
            ->where('tenant_id', $customRecord->tenant_id)
            ->whereKey($user)
            ->firstOrFail();

        $this->watcherEligibility->assertMayWatch(
            $customRecord,
            [(string) $target->getKey()],
            'user',
            __('i18n.backend.actions.records.sync_record_watchers_action.only_users_of_this_tenant_who_may_view_the'),
        );

        $watcher = $this->watcherAutoSubscriber->subscribe(
            (string) $customRecord->getKey(),
            (string) $target->getKey(),
            WatcherSource::Manual,
            (string) $actor->getKey(),
        );

        return $this->watcherResponse($request, $watcher);
    }

    private function authorizeManage(User $user, CustomRecord $record): void
    {
        $ability = "{$record->objectType->slug}.".ObjectTypeAbility::WatchersManage->value;

        if (!$user->hasPermission($ability)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.watchers.watchers_controller.you_may_not_manage_watchers_for_this_record'));
        }
    }

    private function authorizeView(User $user, CustomRecord $record): void
    {
        if ($user->cannot('view', $record)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.watchers.watchers_controller.you_may_not_view_this_record'));
        }
    }

    private function resolveRecord(string $id): CustomRecord
    {
        return CustomRecord::query()
            ->with('objectType')
            ->whereKey($id)
            ->firstOrFail();
    }

    private function watcherResponse(Request $request, RecordWatcher $watcher, int $status = 201): JsonResponse
    {
        return new JsonResponse(
            ['data' => WatcherResource::make($watcher->loadMissing('user'))->resolve($request)],
            $status,
        );
    }
}
