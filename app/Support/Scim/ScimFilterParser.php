<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\DTOs\Scim\ScimFilter;
use App\Exceptions\Scim\ScimException;
use Illuminate\Support\Str;

class ScimFilterParser
{
    private string $equalityPattern = '/^\s*([A-Za-z][A-Za-z0-9._-]*)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i';

    /**
     * @param  list<string>  $allowedAttributes
     *
     * @throws ScimException
     */
    public function parse(?string $filter, array $allowedAttributes): ?ScimFilter
    {
        if ($filter === null || trim($filter) === '') {
            return null;
        }

        if (preg_match($this->equalityPattern, $filter, $matches) !== 1) {
            throw ScimException::invalidFilter();
        }

        $attribute = Str::lower($matches[1]);
        $value = json_decode("\"{$matches[2]}\"");

        if (!in_array($attribute, $allowedAttributes, true) || !is_string($value)) {
            throw ScimException::invalidFilter();
        }

        return new ScimFilter($attribute, $value);
    }
}
