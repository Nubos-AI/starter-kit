<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Storage\AttachmentStorage;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\AttachmentTimelineWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class AttachFileAction
{
    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly AttachmentTimelineWriter $timelineWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(array $input): Attachment
    {
        $validated = Validator::make(
            $input,
            [
                'record_id' => [
                    'required',
                    'string',
                    Rule::exists('custom_records', 'id')->where('tenant_id', (string) TenantContext::currentId()),
                ],
                'field_key' => ['nullable', 'string'],
                'file' => ['required', 'file'],
                'uploaded_by' => ['nullable', 'string'],
                'disk' => ['nullable', 'string'],
            ]
        )->validate();

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $fieldKey = $validated['field_key'] ?? null;

        $record = CustomRecord::query()->whereKey($validated['record_id'])->firstOrFail();
        $field = $fieldKey === null ? null : $this->resolveFileField($record, $fieldKey);

        $this->validateFile($file, $field);

        $tenantId = $record->tenant_id;
        $multiple = ($field->config['multiple'] ?? false) === true;

        $stored = $this->storage->store(
            $file,
            $this->directoryFor($tenantId, $record->getKey()),
            isset($validated['disk']) ? (string) $validated['disk'] : null,
        );

        try {
            return DB::transaction(function () use ($file, $record, $fieldKey, $tenantId, $multiple, $validated, $stored): Attachment {
                $record = CustomRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                $attachment = Attachment::query()->create([
                    'tenant_id' => $tenantId,
                    'record_id' => $record->getKey(),
                    'field_key' => $fieldKey,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType() ?? $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'disk' => $stored['disk'],
                    'path' => $stored['path'],
                    'uploaded_by' => isset($validated['uploaded_by']) ? (string) $validated['uploaded_by'] : null,
                ]);

                $this->timelineWriter->record($record, [['attachment' => $attachment]]);

                if ($fieldKey !== null) {
                    $this->referenceAttachment($record, $fieldKey, $attachment->getKey(), $multiple);
                }

                return $attachment;
            });
        } catch (Throwable $exception) {
            $this->storage->delete($stored['disk'], $stored['path']);

            throw $exception;
        }
    }

    private function resolveFileField(CustomRecord $record, string $fieldKey): FieldDefinition
    {
        $field = FieldDefinition::query()
            ->where('object_type_id', $record->object_type_id)
            ->where('key', $fieldKey)
            ->first();

        if ($field === null || $field->field_type !== FieldType::File) {
            throw ValidationException::withMessages([
                'field_key' => __('i18n.backend.actions.engine.attach_file_action.this_field_is_not_a_file_field_of_the'),
            ]);
        }

        return $field;
    }

    private function validateFile(UploadedFile $file, ?FieldDefinition $field): void
    {
        $config = $field->config ?? [];
        $maxSize = isset($config['max_size']) && is_numeric($config['max_size'])
            ? (int) $config['max_size']
            : (int) config('engine.attachments.max_size_kb');

        /** @var list<string> $defaultMimes */
        $defaultMimes = config('engine.attachments.allowed_mimes', []);

        $allowedMimes = isset($config['allowed_mimes']) && is_array($config['allowed_mimes']) && $config['allowed_mimes'] !== []
            ? array_values(array_filter($config['allowed_mimes'], 'is_string'))
            : $defaultMimes;

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.$maxSize, 'mimetypes:'.implode(',', $allowedMimes)],
        ], [
            'file.max' => __('i18n.backend.actions.engine.attach_file_action.the_file_exceeds_the_allowed_size'),
            'file.mimetypes' => __('i18n.backend.actions.engine.attach_file_action.this_file_type_is_not_allowed'),
        ])->validate();

        $extensions = array_merge(...array_map(
            static fn (string $mime): array => MimeTypes::getDefault()->getExtensions($mime),
            $allowedMimes,
        ));

        if (!in_array(strtolower($file->getClientOriginalExtension()), $extensions, true)) {
            throw ValidationException::withMessages(['file' => __('i18n.backend.actions.engine.attach_file_action.this_file_extension_is_not_allowed')]);
        }
    }

    private function referenceAttachment(CustomRecord $record, string $fieldKey, string $attachmentId, bool $multiple): void
    {
        $data = $record->data ?? [];

        if ($multiple) {
            $existing = isset($data[$fieldKey]) && is_array($data[$fieldKey]) ? $data[$fieldKey] : [];
            $existing[] = $attachmentId;
            $data[$fieldKey] = array_values($existing);
        } else {
            $data[$fieldKey] = $attachmentId;
        }

        $record->data = $data;
        $record->save();
    }

    private function directoryFor(string $tenantId, string $recordId): string
    {
        return 'attachments/'.$tenantId.'/'.$recordId;
    }
}
