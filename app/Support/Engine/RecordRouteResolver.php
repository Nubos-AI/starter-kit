<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class RecordRouteResolver
{
    public static string $ulidPattern = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

    public static string $businessKeyPattern = '[A-Z0-9]{1,4}-[A-Z0-9]{1,10}';

    public static function routePattern(): string
    {
        return self::$ulidPattern.'|'.self::$businessKeyPattern;
    }

    public function resolve(string $identifier, bool $withTrashed = false): CustomRecord
    {
        $query = CustomRecord::query()
            ->with('objectType.fieldDefinitions')
            ->when($withTrashed, fn (Builder $builder): Builder => $builder->withTrashed());

        $record = Str::isUlid($identifier)
            ? $query->whereKey($identifier)->first()
            : $query->where('record_number', $identifier)->first();

        if (!$record instanceof CustomRecord) {
            throw (new ModelNotFoundException)->setModel(CustomRecord::class, [$identifier]);
        }

        return $record;
    }
}
