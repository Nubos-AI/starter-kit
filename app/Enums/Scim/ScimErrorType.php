<?php

declare(strict_types=1);

namespace App\Enums\Scim;

enum ScimErrorType: string
{
    case InvalidFilter = 'invalidFilter';

    case Uniqueness = 'uniqueness';

    case Mutability = 'mutability';

    case InvalidSyntax = 'invalidSyntax';

    case InvalidValue = 'invalidValue';
}
