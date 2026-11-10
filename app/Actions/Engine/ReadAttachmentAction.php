<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\Attachment;
use App\Support\Storage\AttachmentStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReadAttachmentAction
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    /** @return resource */
    public function execute(Attachment $attachment)
    {
        $stream = $this->storage->readStream($attachment->disk, $attachment->path);

        if (!is_resource($stream)) {
            throw new NotFoundHttpException(__('i18n.backend.actions.engine.read_attachment_action.the_file_is_no_longer_present_in_storage'));
        }

        return $stream;
    }
}
