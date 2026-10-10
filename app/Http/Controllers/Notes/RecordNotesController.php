<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notes;

use App\Actions\Notes\CreateRecordNoteAction;
use App\Actions\Notes\DeleteRecordNoteAction;
use App\Actions\Notes\UpdateRecordNoteAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Notes\RecordNoteResource;
use App\Models\CustomRecord;
use App\Models\RecordNote;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class RecordNotesController extends Controller
{
    use RespondsWithValidationErrors;

    public function __construct(
        private readonly CreateRecordNoteAction $createRecordNoteAction,
        private readonly UpdateRecordNoteAction $updateRecordNoteAction,
        private readonly DeleteRecordNoteAction $deleteRecordNoteAction,
    ) {}

    public function index(CustomRecord $record): AnonymousResourceCollection
    {
        $this->authorize('view', $record);

        $notes = RecordNote::query()
            ->where('record_id', $record->getKey())
            ->with('author')
            ->orderByDesc('created_at')
            ->get();

        return RecordNoteResource::collection($notes);
    }

    public function store(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('view', $record);

        try {
            $note = $this->createRecordNoteAction->execute([
                'record_id' => (string) $record->getKey(),
                'body' => $request->input('body'),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return $this->noteResponse($request, $note, 201);
    }

    public function update(Request $request, RecordNote $note): JsonResponse
    {
        $this->authorize('update', $note);

        try {
            $updated = $this->updateRecordNoteAction->execute($note, ['body' => $request->input('body')]);
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return $this->noteResponse($request, $updated);
    }

    public function destroy(RecordNote $note): JsonResponse
    {
        $this->authorize('delete', $note);

        $this->deleteRecordNoteAction->execute($note);

        return new JsonResponse(status: 204);
    }

    private function noteResponse(Request $request, RecordNote $note, int $status = 200): JsonResponse
    {
        return new JsonResponse(
            ['data' => RecordNoteResource::make($note->loadMissing('author'))->resolve($request)],
            $status,
        );
    }
}
