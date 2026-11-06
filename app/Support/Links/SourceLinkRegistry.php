<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Contracts\Links\SourceLinkResolverInterface;
use App\Exceptions\Links\UnknownSourceLinkException;
use App\Support\Abstracts\ConfigDrivenRegistry;

/**
 * @extends ConfigDrivenRegistry<SourceLinkResolverInterface>
 */
class SourceLinkRegistry extends ConfigDrivenRegistry
{
    private string $keyPattern = '/^[a-z][a-z0-9_]*$/';

    public function has(string $sourceType): bool
    {
        return $this->entryClass($sourceType) !== null;
    }

    /**
     * @throws UnknownSourceLinkException
     */
    public function resolverFor(string $sourceType): SourceLinkResolverInterface
    {
        return $this->resolve($this->entryClass($sourceType) ?? throw new UnknownSourceLinkException($sourceType));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_filter(
            array_keys($this->entryClasses()),
            fn (string $sourceType): bool => $this->has($sourceType),
        ));
    }

    /**
     * @return class-string<SourceLinkResolverInterface>|null
     */
    protected function entryClass(string $key): ?string
    {
        return preg_match($this->keyPattern, $key) === 1 ? parent::entryClass($key) : null;
    }

    protected function configKey(): string
    {
        return 'links.sources';
    }

    /**
     * @return class-string<SourceLinkResolverInterface>
     */
    protected function contract(): string
    {
        return SourceLinkResolverInterface::class;
    }
}
