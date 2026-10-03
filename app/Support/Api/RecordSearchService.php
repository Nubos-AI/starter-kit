<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\Api\ApiAccessLevel;
use App\Enums\Api\SearchSource;
use App\Enums\CustomFields\FieldType;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Search\SearchableObjectTypes;
use App\Support\Search\SearchVisibilityFilter;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use ReflectionMethod;

class RecordSearchService
{
    public function __construct(
        private readonly FieldTypeRegistry $registry,
        private readonly ApiAbilityMap $abilityMap,
    ) {}

    private int $hitLimit = 25;

    /**
     * @var list<string>
     */
    private array $passthroughMethods = ['cast', 'toSearchable', 'searchableValue'];

    /**
     * @param  EloquentCollection<int, ObjectType>|null  $objectTypes
     * @param  EloquentCollection<int, FieldDefinition>|null  $fieldDefinitions
     */
    public function search(
        User $user,
        string $term,
        ?EloquentCollection $objectTypes = null,
        ?EloquentCollection $fieldDefinitions = null,
    ): RecordSearchResult {
        $groups = $this->searchGroups($user, $objectTypes, $fieldDefinitions);

        if ($groups === []) {
            return new RecordSearchResult(new EloquentCollection, SearchSource::Index);
        }

        try {
            return new RecordSearchResult($this->searchViaIndex($user, $term, $groups), SearchSource::Index);
        } catch (CommunicationException|ApiException $exception) {
            Log::warning('Record search index unavailable, falling back to the database path.', [
                'user_id' => $user->getKey(),
                'exception' => $exception,
            ]);

            return new RecordSearchResult($this->buildDatabaseQuery($term, $groups)->get(), SearchSource::Fallback);
        }
    }

    /**
     * @param  array{objectTypeIds: list<string>, attributes: list<string>}  $group
     * @return Builder<CustomRecord>
     */
    public function buildIndexQuery(User $user, string $term, array $group): Builder
    {
        return CustomRecord::search($term)
            ->options([
                'filter' => SearchVisibilityFilter::forObjectTypes($user, $group['objectTypeIds']),
                'attributesToSearchOn' => $group['attributes'],
            ])
            ->query(fn (EloquentBuilder $query): EloquentBuilder => $query
                ->with('objectType')
                ->whereIn('object_type_id', $group['objectTypeIds']))
            ->take($this->hitLimit);
    }

    /**
     * @param  list<array{objectTypeIds: list<string>, attributes: list<string>}>  $groups
     * @return EloquentCollection<int, CustomRecord>
     */
    public function searchViaIndex(User $user, string $term, array $groups): EloquentCollection
    {
        /** @var EloquentCollection<int, CustomRecord> $hits */
        $hits = new EloquentCollection;

        foreach ($groups as $group) {
            if ($hits->count() >= $this->hitLimit) {
                break;
            }

            /** @var EloquentCollection<int, CustomRecord> $groupHits */
            $groupHits = $this->buildIndexQuery($user, $term, $group)->get();

            $hits = $hits->merge($groupHits);
        }

        return $hits->take($this->hitLimit)->values();
    }

    /**
     * @param  EloquentCollection<int, ObjectType>|null  $objectTypes
     * @param  EloquentCollection<int, FieldDefinition>|null  $fieldDefinitions
     * @return list<array{objectTypeIds: list<string>, attributes: list<string>}>
     */
    public function searchGroups(
        User $user,
        ?EloquentCollection $objectTypes = null,
        ?EloquentCollection $fieldDefinitions = null,
    ): array {
        $token = $user->currentAccessToken();

        $searchable = SearchableObjectTypes::for(
            $user,
            fn (ObjectType $objectType): bool => $this->abilityMap->satisfiesObjectType($token, $objectType, ApiAccessLevel::Read),
            $objectTypes,
            $fieldDefinitions,
        );

        $unrestricted = ['objectTypeIds' => [], 'attributes' => []];
        $groups = [];

        foreach ($searchable as $objectTypeId => $entry) {
            if ($entry['isRestricted']) {
                $groups[] = ['objectTypeIds' => [$objectTypeId], 'attributes' => $entry['attributes']];

                continue;
            }

            $unrestricted['objectTypeIds'][] = $objectTypeId;
            $unrestricted['attributes'] = array_values(array_unique([...$unrestricted['attributes'], ...$entry['attributes']]));
        }

        return $unrestricted['objectTypeIds'] === [] ? $groups : [$unrestricted, ...$groups];
    }

    /**
     * @param  list<array{objectTypeIds: list<string>, attributes: list<string>}>  $groups
     * @return EloquentBuilder<CustomRecord>
     */
    public function buildDatabaseQuery(string $term, array $groups): EloquentBuilder
    {
        $pattern = '%'.addcslashes($term, '%_\\').'%';
        $passthroughTypes = $this->scalarPassthroughFieldTypes();

        return CustomRecord::query()
            ->with('objectType')
            ->where(function (EloquentBuilder $query) use ($groups, $pattern, $passthroughTypes): void {
                foreach ($groups as $group) {
                    $query->orWhere(function (EloquentBuilder $scoped) use ($group, $pattern, $passthroughTypes): void {
                        $scoped->whereIn('custom_records.object_type_id', $group['objectTypeIds'])
                            ->whereExists(function (QueryBuilder $query) use ($group, $pattern, $passthroughTypes): void {
                                $query->selectRaw('1')
                                    ->from('field_definitions')
                                    ->whereColumn('field_definitions.object_type_id', 'custom_records.object_type_id')
                                    ->whereIn('field_definitions.key', $group['attributes'])
                                    ->where('field_definitions.is_searchable', true)
                                    ->where('field_definitions.is_encrypted', false)
                                    ->where('field_definitions.is_translatable', false)
                                    ->whereIn('field_definitions.field_type', $passthroughTypes)
                                    ->whereNull('field_definitions.deleted_at')
                                    ->whereRaw('jsonb_typeof(custom_records.data -> "field_definitions"."key") = ?', ['string'])
                                    ->whereRaw('custom_records.data ->> "field_definitions"."key" ILIKE ?', [$pattern]);
                            });
                    });
                }
            })
            ->orderByDesc('id')
            ->take($this->hitLimit);
    }

    /**
     * @return list<string>
     */
    private function scalarPassthroughFieldTypes(): array
    {
        $registry = $this->registry;

        return array_values(array_map(
            static fn (FieldType $type): string => $type->value,
            array_filter(
                FieldType::cases(),
                fn (FieldType $type): bool => $this->isScalarPassthrough($registry, $type),
            ),
        ));
    }

    private function isScalarPassthrough(FieldTypeRegistry $registry, FieldType $type): bool
    {
        if (!$registry->hasHandler($type)) {
            return false;
        }

        $handler = $registry->handlerFor($type);

        foreach ($this->passthroughMethods as $method) {
            if ((new ReflectionMethod($handler, $method))->getDeclaringClass()->getName() !== AbstractFieldHandler::class) {
                return false;
            }
        }

        return true;
    }
}

readonly class RecordSearchResult
{
    /**
     * @param  EloquentCollection<int, CustomRecord>  $hits
     */
    public function __construct(
        public EloquentCollection $hits,
        public SearchSource $source,
    ) {}
}
