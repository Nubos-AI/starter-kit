<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\AttachFileAction;
use App\Actions\Engine\DeleteAttachmentAction;
use App\Actions\Engine\ReadAttachmentAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Engine\AttachmentResource;
use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordFilesController extends Controller
{
    public function __construct(
        private readonly AttachFileAction $attachFile,
        private readonly DeleteAttachmentAction $deleteAttachment,
        private readonly ReadAttachmentAction $readAttachment,
    ) {}

    public function index(Request $request, CustomRecord $record): AnonymousResourceCollection
    {
        $this->authorize('view', $record);

        $readable = FieldVisibilityResolver::forRequest()->readableFieldKeys($request->user(), $record->object_type_id);
        $attachments = Attachment::query()
            ->where('record_id', $record->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNull('field_key')->orWhereIn('field_key', $readable))
            ->with('uploadedBy')
            ->orderByDesc('id')
            ->paginate(25);

        $attachments->getCollection()->each(fn (Attachment $attachment): Attachment => $attachment->setRelation('record', $record));

        return AttachmentResource::collection($attachments)->additional(['permissions' => [
            'canUpload' => !$record->trashed() && $request->user()->can('update', $record),
        ], 'upload' => [
            'maxSizeKb' => (int) config('engine.attachments.max_size_kb'),
            'allowedMimes' => config('engine.attachments.allowed_mimes'),
        ]]);
    }

    public function store(Request $request, CustomRecord $record): JsonResponse
    {
        $this->authorize('view', $record);
        $this->authorize('update', $record);

        $attachment = $this->attachFile->execute([
            'record_id' => $record->getKey(),
            'file' => $request->file('file'),
            'uploaded_by' => $request->user()->getAuthIdentifier(),
        ]);
        $attachment->setRelation('record', $record)->load('uploadedBy');

        return AttachmentResource::make($attachment)->response()->setStatusCode(201);
    }

    public function show(Request $request, CustomRecord $record, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $record);
        abort_unless(!$attachment->trashed() && $attachment->record_id === $record->getKey(), 404);
        $attachment->setRelation('record', $record);
        $this->authorize('view', $attachment);

        $stream = $this->readAttachment->execute($attachment);
        $preview = $request->boolean('preview') && in_array($attachment->mime, [
            'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain',
        ], true);

        return response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $attachment->original_name, [
            'Content-Type' => $preview ? $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ], $preview ? 'inline' : 'attachment');
    }

    public function destroy(CustomRecord $record, Attachment $attachment): JsonResponse
    {
        $this->authorize('view', $record);
        abort_unless($attachment->record_id === $record->getKey(), 404);
        $attachment->setRelation('record', $record);
        $this->authorize('delete', $attachment);

        $this->deleteAttachment->execute($attachment);

        return new JsonResponse(status: 204);
    }
}
