<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Exceptions\Engine\RecordNumberOverflowException;
use App\Models\ObjectType;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecordNumberFormatter
{
    public static string $defaultFormat = '##########';

    public static string $placeholder = '#';

    public function next(ObjectType $objectType, string $tenantId): ?string
    {
        return $this->format($objectType, $this->reserve($objectType, $tenantId, 1));
    }

    public function format(ObjectType $objectType, int $sequence): ?string
    {
        $prefix = $objectType->business_key_prefix;

        if ($prefix === null || $prefix === '') {
            return null;
        }

        $format = $objectType->record_number_format;
        $format = $format === null || $format === '' ? self::$defaultFormat : $format;

        return $prefix.'-'.$this->applyFormat($format, $sequence);
    }

    /**
     * @throws Throwable
     */
    public function reserve(ObjectType $objectType, string $tenantId, int $count): int
    {
        $objectTypeId = (string) $objectType->getKey();

        return DB::transaction(function () use ($tenantId, $objectTypeId, $count): int {
            DB::table('record_counters')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'object_type_id' => $objectTypeId,
                'next_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::selectOne(
                'UPDATE record_counters
                    SET next_value = next_value + ?, updated_at = ?
                  WHERE tenant_id = ? AND object_type_id = ?
              RETURNING next_value',
                [$count, now(), $tenantId, $objectTypeId],
            );

            return (int) $row->next_value - $count;
        });
    }

    private function applyFormat(string $format, int $sequence): string
    {
        $places = substr_count($format, self::$placeholder);
        $digits = str_pad((string) $sequence, $places, '0', STR_PAD_LEFT);

        if (strlen($digits) > $places) {
            throw RecordNumberOverflowException::for($format, $sequence);
        }

        $position = 0;

        return implode('', array_map(
            function (string $character) use ($digits, &$position): string {
                if ($character !== self::$placeholder) {
                    return $character;
                }

                return $digits[$position++];
            },
            str_split($format),
        ));
    }
}
