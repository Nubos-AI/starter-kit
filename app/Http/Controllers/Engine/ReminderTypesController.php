<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\BulkDeleteReminderTypesAction;
use App\Actions\Engine\CreateReminderTypeAction;
use App\Actions\Engine\DeleteReminderTypeAction;
use App\Actions\Engine\UpdateReminderTypeAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ReminderType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReminderTypesController extends Controller
{
    public function __construct(
        private readonly CreateReminderTypeAction $createReminderType,
        private readonly UpdateReminderTypeAction $updateReminderType,
        private readonly DeleteReminderTypeAction $deleteReminderType,
        private readonly BulkDeleteReminderTypesAction $bulkDeleteReminderTypes,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        $reminderTypes = ReminderType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ReminderType $type): array => $this->indexPayload($type, $user))
            ->all();

        return Inertia::render('reminderTypes/Index', [
            'reminderTypes' => $reminderTypes,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('reminderTypes/Form', [
            'mode' => 'create',
            'reminderType' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->createReminderType->execute($request->all());

        return to_route('engine.reminder-types.index');
    }

    public function edit(ReminderType $reminderType): Response
    {
        return Inertia::render('reminderTypes/Form', [
            'mode' => 'edit',
            'reminderType' => $this->payload($reminderType),
        ]);
    }

    public function update(Request $request, ReminderType $reminderType): RedirectResponse
    {
        $this->updateReminderType->execute($reminderType, $request->all());

        return to_route('engine.reminder-types.index');
    }

    public function destroy(ReminderType $reminderType): RedirectResponse
    {
        $this->deleteReminderType->execute($reminderType);

        return to_route('engine.reminder-types.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->bulkDeleteReminderTypes->execute($this->actingUser($request), $request->all());

        return to_route('engine.reminder-types.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function indexPayload(ReminderType $type, User $user): array
    {
        return [
            ...$this->payload($type),
            'can_update' => $user->hasPermission('reminder-types.update'),
            'can_delete' => $user->hasPermission('reminder-types.delete'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function payload(ReminderType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
        ];
    }
}
