<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\CreateRecordWithCollaboratorsAction;
use App\Actions\Engine\DeleteRecordAction;
use App\Actions\Engine\DuplicateRecordAction;
use App\Actions\Engine\UpdateRecordCellAction;
use App\Actions\Engine\UpdateRecordWithCollaboratorsAction;
use App\Exceptions\StaleRecordException;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Traits\Engine\RespondsWithRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class RecordWriteController extends Controller
{
    use RespondsWithRecords;

    /** @var list<string> */
    private array $creatableKeys = ['data', 'owner_id', 'external_reference_id'];

    /** @var list<string> */
    private array $writableKeys = ['data', 'owner_id', 'external_reference_id'];

    public function __construct(
        private readonly CreateRecordWithCollaboratorsAction $createRecordAction,
        private readonly UpdateRecordWithCollaboratorsAction $updateRecordAction,
        private readonly UpdateRecordCellAction $updateRecordCellAction,
        private readonly DeleteRecordAction $deleteRecordAction,
        private readonly DuplicateRecordAction $duplicateRecordAction,
    ) {}

    public function store(Request $request, ObjectType $objectType): JsonResponse
    {
        $this->authorizePermission($request, "{$objectType->slug}.create");

        try {
            $record = $this->createRecordAction->execute(
                array_merge(
                    $request->only([...$this->creatableKeys, ...config('modules.records.creatable_keys', [])]),
                    ['object_type_id' => $objectType->getKey()],
                ),
                $this->collaborators($request),
                isUserInput: true,
            );
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return $this->recordResponse($request, $record, 201);
    }

    public function update(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('update', $record);

        try {
            $updated = $this->updateRecordAction->execute(
                $record,
                $request->only([...$this->writableKeys, 'version']),
                $this->collaborators($request),
            );
        } catch (StaleRecordException $exception) {
            throw $exception->withRecord($this->freshRecord($record));
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return $this->recordResponse($request, $updated);
    }

    public function updateCell(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('update', $record);

        try {
            $updated = $this->updateRecordCellAction->execute($record, $request->only(['field', 'value', 'version']));
        } catch (StaleRecordException $exception) {
            throw $exception->withRecord($this->freshRecord($record));
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return $this->recordResponse($request, $updated);
    }

    public function duplicate(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('view', $record);
        $this->authorizePermission($request, "{$record->objectType->slug}.create");

        return $this->recordResponse($request, $this->duplicateRecordAction->execute($record), 201);
    }

    public function destroy(Request $request, string $record): JsonResponse|Response
    {
        $user = $this->actingUser($request);
        $record = CustomRecord::query()->whereKey($record)->firstOrFail();

        if ($user->cannot('delete', $record)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.engine.record_write_controller.you_may_not_delete_this_record'));
        }

        try {
            $this->deleteRecordAction->execute($record, $request->only(['deletion_reason']));
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collaborators(Request $request): ?array
    {
        return $request->has('collaborator_ids') ? $request->only('collaborator_ids') : null;
    }
}
