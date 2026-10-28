<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use Closure;
use Illuminate\Support\Str;

class PayloadRewriter
{
    /**
     * @var list<string>
     */
    private array $nonPortableMarkers;

    private string $placeholder;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(private readonly BusinessKeyResolver $resolver)
    {
        $this->placeholder = (string) config('engine.config_bundle.placeholder');

        $markers = config('engine.config_bundle.non_portable_markers');
        $this->nonPortableMarkers = array_values(array_map(
            static fn (mixed $marker): string => (string) $marker,
            is_array($markers) ? $markers : [],
        ));
    }

    /**
     * @return Closure(mixed, string): mixed
     */
    public function translatorFor(string $identifier): Closure
    {
        return fn (mixed $value, string $path): mixed => is_string($value) && Str::isUlid($value)
            ? $this->translate($path, $value, $identifier)
            : $value;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function translate(string $path, string $ulid, string $identifier): string
    {
        $key = $this->resolver->resolve($ulid);

        if ($key !== null) {
            return $key;
        }

        $this->warnings[] = "{$identifier} → {$path}: ".$this->reasonFor($path);

        return $this->placeholder;
    }

    private function reasonFor(string $path): string
    {
        foreach ($this->nonPortableMarkers as $marker) {
            if (str_contains($path, $marker)) {
                return __('i18n.backend.support.config_bundle.payload_rewriter.this_reference_points_to_a_person_team_or_individual');
            }
        }

        return __('i18n.backend.support.config_bundle.payload_rewriter.this_reference_points_to_an_artifact_that_was_not');
    }
}
