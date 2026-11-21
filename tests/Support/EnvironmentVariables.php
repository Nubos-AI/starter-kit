<?php

declare(strict_types=1);

namespace Tests\Support;

class EnvironmentVariables
{
    /**
     * @var array<string, string|false>
     */
    private array $previous = [];

    /**
     * @param  array<string, string|null>  $variables
     */
    public function set(array $variables): void
    {
        foreach ($variables as $name => $value) {
            if (!array_key_exists($name, $this->previous)) {
                $this->previous[$name] = getenv($name);
            }

            $this->apply($name, $value);
        }
    }

    public function restore(): void
    {
        foreach ($this->previous as $name => $value) {
            $this->apply($name, $value === false ? null : $value);
        }

        $this->previous = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(string $file): array
    {
        return require base_path("config/{$file}.php");
    }

    private function apply(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
