<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\Exceptions\ConfigBundle\MalformedBundleException;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Enumerable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class YamlCodec
{
    private int $inlineDepth = 32;

    private int $indentation = 2;

    /** @var int<0, 64721> */
    private int $dumpFlags = Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE;

    private int $maxFloatPrecision = 17;

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  (Closure(mixed, string): mixed)|null  $onScalar
     * @return array<array-key, mixed>
     *
     * @throws MalformedBundleException
     */
    public function normalize(array $payload, ?Closure $onScalar = null): array
    {
        return $this->normalizeArray($payload, '', $onScalar);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function dump(array $payload): string
    {
        return Yaml::dump($payload, $this->inlineDepth, $this->indentation, $this->dumpFlags);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws MalformedBundleException
     */
    public function parse(string $yaml, string $relativePath): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw MalformedBundleException::parseFailed($relativePath, $exception);
        }

        if (!is_array($parsed)) {
            throw MalformedBundleException::notAMapping($relativePath);
        }

        return $parsed;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  (Closure(mixed, string): mixed)|null  $onScalar
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $payload, string $path, ?Closure $onScalar): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            $child = is_int($key) ? "{$path}[{$key}]" : ($path === '' ? $key : "{$path}.{$key}");

            $normalized[$key] = $this->normalizeValue($value, $child, $onScalar);
        }

        return $normalized;
    }

    /**
     * @param  (Closure(mixed, string): mixed)|null  $onScalar
     */
    private function normalizeValue(mixed $value, string $path, ?Closure $onScalar): mixed
    {
        if (is_array($value)) {
            return $this->normalizeArray($value, $path, $onScalar);
        }

        if ($value instanceof Enumerable) {
            return $this->normalizeArray($value->all(), $path, $onScalar);
        }

        $scalar = $this->normalizeScalar($value, $path);

        return $onScalar === null ? $scalar : $onScalar($scalar, $path);
    }

    private function normalizeScalar(mixed $value, string $path): mixed
    {
        if ($value === null || is_int($value) || is_bool($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $this->normalizeFloat($value, $path);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::ATOM);
        }

        throw MalformedBundleException::unrepresentableValue($path, get_debug_type($value));
    }

    private function normalizeFloat(float $value, string $path): string
    {
        if (is_nan($value)) {
            throw MalformedBundleException::unrepresentableValue($path, 'float (NAN)');
        }

        if (is_infinite($value)) {
            throw MalformedBundleException::unrepresentableValue($path, 'float (INF)');
        }

        for ($precision = 1; $precision <= $this->maxFloatPrecision; $precision++) {
            $candidate = sprintf("%.{$precision}G", $value);

            if ((float) $candidate === $value) {
                return $candidate;
            }
        }

        throw MalformedBundleException::unrepresentableValue($path, 'float');
    }
}
