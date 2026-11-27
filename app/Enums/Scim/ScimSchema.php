<?php

declare(strict_types=1);

namespace App\Enums\Scim;

enum ScimSchema: string
{
    case User = 'urn:ietf:params:scim:schemas:core:2.0:User';

    case Group = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    case ListResponse = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    case Error = 'urn:ietf:params:scim:api:messages:2.0:Error';

    case PatchOp = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    case ServiceProviderConfig = 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig';

    case ResourceType = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';

    case Schema = 'urn:ietf:params:scim:schemas:core:2.0:Schema';
}
