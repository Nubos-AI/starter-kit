<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Models\User;

class SearchVisibilityFilter
{
    /**
     * @return non-empty-string
     */
    public static function for(User $viewer): string
    {
        $tenantId = app()->bound('current_tenant')
            ? app('current_tenant')->getKey()
            : $viewer->tenant_id;

        return self::equals('tenant_id', $tenantId);
    }

    /**
     * @param  list<string>  $objectTypeIds
     * @return non-empty-string
     */
    public static function forObjectTypes(User $viewer, array $objectTypeIds): string
    {
        $quoted = array_map(static fn (string $id): string => self::quote($id), $objectTypeIds);

        return self::for($viewer).' AND object_type_id IN ['.implode(', ', $quoted).']';
    }

    /**
     * @return non-empty-string
     */
    private static function equals(string $attribute, ?string $value): string
    {
        return $attribute.' = '.self::quote((string) $value);
    }

    /**
     * @return non-empty-string
     */
    private static function quote(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
