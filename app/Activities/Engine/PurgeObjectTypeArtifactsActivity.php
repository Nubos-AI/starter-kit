<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Actions\Engine\DeleteObjectTypeFilesAction;
use App\Actions\Engine\DropObjectTypeIndexesAction;
use App\Contracts\Engine\PurgeObjectTypeArtifactsActivityInterface;
use App\DTOs\Engine\ObjectTypePurgeData;
use App\Support\Temporal\ProcessStepRunner;

class PurgeObjectTypeArtifactsActivity implements PurgeObjectTypeArtifactsActivityInterface
{
    public function __construct(
        private readonly DropObjectTypeIndexesAction $dropIndexes,
        private readonly DeleteObjectTypeFilesAction $deleteFiles,
        private readonly ProcessStepRunner $steps,
    ) {}

    public function dropObjectTypeIndexes(ObjectTypePurgeData $input): void
    {
        $this->steps->run(
            'object-types.drop-indexes',
            ['tenant_id' => $input->tenantId, 'object_type_id' => $input->objectTypeId],
            fn () => $this->dropIndexes->execute($input->objectTypeId),
        );
    }

    public function deleteObjectTypeFiles(ObjectTypePurgeData $input): int
    {
        return $this->steps->run(
            'object-types.delete-files',
            ['tenant_id' => $input->tenantId, 'object_type_id' => $input->objectTypeId, 'file_count' => count($input->files)],
            fn (): int => $this->deleteFiles->execute($input->objectTypeId, $input->files),
        );
    }
}
