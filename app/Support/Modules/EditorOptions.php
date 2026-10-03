<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\EditorOptionsContributorInterface;
use LogicException;

class EditorOptions
{
    /** @param iterable<EditorOptionsContributorInterface> $contributors */
    public function __construct(private readonly iterable $contributors = []) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $options = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->options() as $key => $value) {
                if (array_key_exists($key, $options)) {
                    throw new LogicException("Duplicate editor option [{$key}].");
                }

                $options[$key] = $value;
            }
        }

        return $options;
    }
}
