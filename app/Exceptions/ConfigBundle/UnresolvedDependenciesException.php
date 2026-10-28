<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use RuntimeException;

class UnresolvedDependenciesException extends RuntimeException
{
    /**
     * @param  list<array{kind: ArtifactKind, key: string, reason: string}>  $refusals
     */
    private function __construct(string $message, public readonly array $refusals)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<array{kind: ArtifactKind, key: string, reason: string}>  $refusals
     */
    public static function forRefusals(array $refusals): self
    {
        $reasons = array_map(
            static fn (array $refusal): string => $refusal['reason'],
            $refusals,
        );

        $message = __('i18n.backend.exceptions.config_bundle.unresolved_dependencies_exception.the_selection_cannot_be_applied_because_prerequisites_are_missing');

        return new self(trim($message.' '.implode(' ', $reasons)), $refusals);
    }
}
