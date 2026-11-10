<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class GateSpy
{
    /**
     * @var list<array{ability: string, arguments: array<int, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  list<string>  $allowed
     */
    private function __construct(private readonly array $allowed) {}

    public static function allowing(string ...$abilities): self
    {
        $spy = new self(array_values($abilities));

        Gate::before(static fn (?Authenticatable $user, string $ability, array $arguments = []): bool => $spy->record($ability, $arguments));

        return $spy;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function record(string $ability, array $arguments): bool
    {
        $this->calls[] = ['ability' => $ability, 'arguments' => $arguments];

        return in_array($ability, $this->allowed, true);
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_map(
            static fn (array $call): string => $call['ability'],
            $this->calls,
        );
    }

    public function wasAskedFor(string $ability, ?Model $subject = null): bool
    {
        foreach ($this->calls as $call) {
            if ($call['ability'] !== $ability) {
                continue;
            }

            if (!$subject instanceof Model) {
                return true;
            }

            $asked = $call['arguments'][0] ?? null;

            if ($asked instanceof Model && $asked::class === $subject::class && $asked->getKey() === $subject->getKey()) {
                return true;
            }
        }

        return false;
    }
}
