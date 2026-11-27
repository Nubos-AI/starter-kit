<?php

declare(strict_types=1);

namespace App\Traits\Links;

use Illuminate\Database\Eloquent\Model;

trait CarriesSourceLinks
{
    /**
     * @var list<array{key: string, id: string, label: string, href: string}>
     */
    private array $sourceLinks = [];

    /**
     * @param  array<string, list<array{key: string, id: string, label: string, href: string}>>  $links
     */
    public static function withLinksFrom(Model $item, array $links): static
    {
        return static::make($item)->withLinks($links[(string) $item->getKey()] ?? []);
    }

    /**
     * @param  list<array{key: string, id: string, label: string, href: string}>  $links
     */
    public function withLinks(array $links): static
    {
        $this->sourceLinks = $links;

        return $this;
    }
}
