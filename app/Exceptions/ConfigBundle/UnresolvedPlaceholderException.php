<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;
use RuntimeException;

class UnresolvedPlaceholderException extends RuntimeException
{
    /**
     * @param  list<array{kind: string, key: string}>  $affected
     */
    private function __construct(string $message, public readonly array $affected)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<array{kind: ArtifactKind, key: string}>  $carriers
     */
    public static function forArtifacts(array $carriers): self
    {
        $affected = array_map(
            static fn (array $carrier): array => ['kind' => $carrier['kind']->value, 'key' => $carrier['key']],
            $carriers,
        );

        $identifiers = implode(', ', array_map(
            static fn (array $carrier): string => $carrier['kind']->identifierFor($carrier['key']),
            $carriers,
        ));

        $count = count($affected);

        $message = $count === 1
            ? __('i18n.backend.exceptions.config_bundle.unresolved_placeholder_exception.a_placeholder_is_unresolved_for_one_artifact_enter_the', ['value1' => $identifiers])
            : __('i18n.backend.exceptions.config_bundle.unresolved_placeholder_exception.a_placeholder_is_unresolved_for_artifacts_enter_the_missing', ['value1' => $count, 'value2' => $identifiers]);

        return new self($message, $affected);
    }
}
