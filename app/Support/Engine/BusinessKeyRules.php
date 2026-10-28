<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\ObjectType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class BusinessKeyRules
{
    public static string $prefixPattern = '/^[A-Z0-9]{1,4}$/';

    public static string $formatPattern = '/^[A-Z0-9]*#[A-Z0-9#]*$/';

    public static int $formatMaxLength = 10;

    /**
     * @return array<string, array<int, Unique|string>>
     */
    public function all(?ObjectType $objectType = null): array
    {
        $unique = Rule::unique('object_types', 'business_key_prefix')
            ->where('tenant_id', TenantContext::currentId())
            ->whereNull('deleted_at');
        $presence = 'required';

        if ($objectType instanceof ObjectType) {
            $unique = $unique->ignore($objectType->getKey());
            $presence = 'sometimes';
        }

        return [
            'business_key_prefix' => [$presence, 'string', 'regex:'.self::$prefixPattern, $unique],
            'record_number_format' => [
                'sometimes',
                'nullable',
                'string',
                'max:'.self::$formatMaxLength,
                'regex:'.self::$formatPattern,
            ],
        ];
    }
}
