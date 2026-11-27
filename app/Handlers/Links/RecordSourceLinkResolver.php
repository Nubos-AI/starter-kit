<?php

declare(strict_types=1);

namespace App\Handlers\Links;

use App\Contracts\Links\SourceLinkResolverInterface;
use App\DTOs\Links\SourceLinkData;
use App\Models\CustomRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RecordSourceLinkResolver implements SourceLinkResolverInterface
{
    public static string $key = 'record';

    public function key(): string
    {
        return self::$key;
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, SourceLinkData>
     */
    public function resolve(User $user, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $links = [];

        $records = CustomRecord::query()
            ->with('objectType')
            ->whereIn('custom_records.id', $sourceIds)
            ->get();

        foreach ($records as $record) {
            if (Gate::forUser($user)->allows('view', $record)) {
                $links[(string) $record->getKey()] = new SourceLinkData(
                    label: $record->record_number ?? __('i18n.backend.handlers.links.record_source_link_resolver.record'),
                    href: route('engine.records.show', ['record' => $record->getKey()]),
                );
            }
        }

        return $links;
    }

    public function isAccessible(User $user, string $sourceId): bool
    {
        $record = CustomRecord::query()
            ->with('objectType')
            ->whereKey($sourceId)
            ->first();

        return $record instanceof CustomRecord && Gate::forUser($user)->allows('view', $record);
    }
}
