<?php

declare(strict_types=1);

namespace App\Exceptions\Promotion;

use InvalidArgumentException;

class CyclicArtifactDependencyException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $path
     */
    public static function cycle(array $path): self
    {
        return new self(__('i18n.backend.exceptions.promotion.cyclic_artifact_dependency_exception.the_artifact_dependencies_contain_a_cycle').implode(' → ', $path).'.');
    }
}
