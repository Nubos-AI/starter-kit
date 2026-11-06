<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Contracts\Links\SourceLinkableInterface;
use App\DTOs\Links\SourceLinkData;
use App\Handlers\Links\RecordSourceLinkResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SourceLinkCatalog
{
    public function __construct(private readonly SourceLinkRegistry $registry) {}

    /**
     * @param  iterable<Model&SourceLinkableInterface>  $items
     * @return array<string, list<array{key: string, id: string, label: string, href: string}>>
     */
    public function linksFor(User $user, iterable $items): array
    {
        $resolved = $this->resolveTargets($user, $this->wantedTargets($items));

        $links = [];

        foreach ($items as $item) {
            $links[(string) $item->getKey()] = $this->assembleLinks($item, $resolved);
        }

        return $links;
    }

    /**
     * @return list<array{key: string, id: string, label: string, href: string}>
     */
    public function linksOf(User $user, Model&SourceLinkableInterface $item): array
    {
        return $this->linksFor($user, [$item])[(string) $item->getKey()] ?? [];
    }

    /**
     * @param  iterable<Model&SourceLinkableInterface>  $items
     * @return array<string, list<string>>
     */
    private function wantedTargets(iterable $items): array
    {
        $wanted = [];

        foreach ($items as $item) {
            foreach ($this->targetsOf($item) as [$sourceType, $sourceId]) {
                $wanted[$sourceType][$sourceId] = $sourceId;
            }
        }

        return array_map(array_values(...), $wanted);
    }

    /**
     * @param  array<string, list<string>>  $wanted
     * @return array<string, array<string, SourceLinkData>>
     */
    private function resolveTargets(User $user, array $wanted): array
    {
        $resolved = [];

        foreach ($wanted as $sourceType => $sourceIds) {
            $resolved[$sourceType] = $this->registry->resolverFor($sourceType)->resolve($user, $sourceIds);
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<string, SourceLinkData>>  $resolved
     * @return list<array{key: string, id: string, label: string, href: string}>
     */
    private function assembleLinks(Model&SourceLinkableInterface $item, array $resolved): array
    {
        $links = [];

        foreach ($this->targetsOf($item) as [$sourceType, $sourceId]) {
            $target = $resolved[$sourceType][$sourceId] ?? null;

            if ($target instanceof SourceLinkData) {
                $links[] = [
                    'key' => $sourceType,
                    'id' => $sourceId,
                    'label' => $target->label,
                    'href' => $target->href,
                ];
            }
        }

        return $links;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function targetsOf(Model&SourceLinkableInterface $item): array
    {
        $recordKey = RecordSourceLinkResolver::$key;
        $targets = [];

        $recordId = $item->linkedRecordId();
        $sourceType = $item->linkedSourceType();
        $sourceId = $item->linkedSourceId();

        if ($recordId !== null) {
            $targets[] = [$recordKey, $recordId];
        }

        $isSameRecord = $sourceType === $recordKey && $sourceId === $recordId;

        if ($sourceType !== null && $sourceId !== null && !$isSameRecord && $this->registry->has($sourceType)) {
            $targets[] = [$sourceType, $sourceId];
        }

        return $targets;
    }
}
