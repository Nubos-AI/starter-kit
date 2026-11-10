<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use App\DTOs\Promotion\ArtifactDiff;
use RuntimeException;

class UndecidedConflictsException extends RuntimeException
{
    /**
     * @param  list<array{kind: string, key: string}>  $affected
     */
    private function __construct(string $message, public readonly array $affected)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<ArtifactDiff>  $undecided
     */
    public static function forUndecided(array $undecided): self
    {
        $affected = array_map(
            static fn (ArtifactDiff $diff): array => ['kind' => $diff->kind->value, 'key' => $diff->key],
            $undecided,
        );

        $count = count($affected);

        $message = $count === 1
            ? __('i18n.backend.exceptions.config_bundle.undecided_conflicts_exception.an_artifact_still_needs_a_decision_on_which_version')
            : __('i18n.backend.exceptions.config_bundle.undecided_conflicts_exception.the_version_to_use_has_not_yet_been_decided', ['value1' => $count]);

        return new self($message, $affected);
    }
}
