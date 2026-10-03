<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use RuntimeException;

class AmbiguousArtifactKeyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ArtifactKind $kind,
        public readonly string $firstKey,
        public readonly string $secondKey,
        public readonly ?string $fileName,
    ) {
        parent::__construct($message);
    }

    public static function fileNameCollision(ArtifactKind $kind, string $firstKey, string $secondKey, string $fileName): self
    {
        return new self(
            __('i18n.backend.exceptions.config_bundle.ambiguous_artifact_key_exception.two_artifacts_of_kind_claim_the_same_file_and', ['value1' => $kind->value, 'value2' => $fileName, 'value3' => $firstKey, 'value4' => $secondKey]),
            $kind,
            $firstKey,
            $secondKey,
            $fileName,
        );
    }
}
