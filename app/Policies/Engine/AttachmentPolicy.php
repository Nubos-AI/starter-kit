<?php

declare(strict_types=1);

namespace App\Policies\Engine;

use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Support\Facades\Gate;

class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        $record = $attachment->record;

        if ($attachment->tenant_id !== $user->tenant_id || !$record instanceof CustomRecord || Gate::forUser($user)->denies('view', $record)) {
            return false;
        }

        return $attachment->field_key === null || in_array(
            $attachment->field_key,
            FieldVisibilityResolver::forRequest()->readableFieldKeys($user, $record->object_type_id),
            true,
        );
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if (!$this->view($user, $attachment)) {
            return false;
        }

        $record = $attachment->record;

        return $record instanceof CustomRecord && !$record->trashed()
            && Gate::forUser($user)->allows('update', $record)
            && ($attachment->field_key === null || !in_array(
                $attachment->field_key,
                FieldVisibilityResolver::forRequest()->forbiddenWriteFieldKeys($user, $record->object_type_id),
                true,
            ));
    }
}
