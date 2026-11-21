<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\Enums\Scim\ScimSchema;

class ScimServiceProviderDocuments
{
    /**
     * @return array<string, mixed>
     */
    public function serviceProviderConfig(): array
    {
        return [
            'schemas' => [ScimSchema::ServiceProviderConfig->value],
            'documentationUri' => url('/'),
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => (int) config('sso.scim.max_page_size')],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => true],
            'authenticationSchemes' => [
                [
                    'type' => 'oauthbearertoken',
                    'name' => 'OAuth Bearer Token',
                    'description' => 'Authentication with the SCIM token of the tenant.',
                    'primary' => true,
                ],
            ],
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => url('/scim/v2/ServiceProviderConfig')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resourceTypes(): array
    {
        return [
            $this->resourceType('User', '/Users', ScimSchema::User),
            $this->resourceType('Group', '/Groups', ScimSchema::Group),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function schemas(): array
    {
        return [
            $this->schema(ScimSchema::User, 'User', [
                $this->attribute('userName', 'string', true, 'server'),
                $this->attribute('externalId', 'string', false, 'none'),
                $this->attribute('displayName', 'string', false, 'none', 'readOnly'),
                $this->complexAttribute('name', [
                    $this->attribute('givenName', 'string', false, 'none'),
                    $this->attribute('familyName', 'string', false, 'none'),
                    $this->attribute('formatted', 'string', false, 'none', 'readOnly'),
                ]),
                $this->complexAttribute('emails', [
                    $this->attribute('value', 'string', false, 'server'),
                    $this->attribute('type', 'string', false, 'none'),
                    $this->attribute('primary', 'boolean', false, 'none'),
                ], true),
                $this->attribute('active', 'boolean', false, 'none'),
                $this->complexAttribute('groups', [
                    $this->attribute('value', 'string', false, 'none', 'readOnly'),
                    $this->attribute('display', 'string', false, 'none', 'readOnly'),
                ], true, 'readOnly'),
            ]),
            $this->schema(ScimSchema::Group, 'Group', [
                $this->attribute('displayName', 'string', true, 'none'),
                $this->complexAttribute('members', [
                    $this->attribute('value', 'string', false, 'none'),
                    $this->attribute('display', 'string', false, 'none', 'readOnly'),
                ], true),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceType(string $name, string $endpoint, ScimSchema $schema): array
    {
        return [
            'schemas' => [ScimSchema::ResourceType->value],
            'id' => $name,
            'name' => $name,
            'endpoint' => $endpoint,
            'schema' => $schema->value,
            'meta' => ['resourceType' => 'ResourceType', 'location' => url("/scim/v2/ResourceTypes/{$name}")],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function schema(ScimSchema $schema, string $name, array $attributes): array
    {
        return [
            'schemas' => [ScimSchema::Schema->value],
            'id' => $schema->value,
            'name' => $name,
            'attributes' => $attributes,
            'meta' => ['resourceType' => 'Schema', 'location' => url("/scim/v2/Schemas/{$schema->value}")],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(string $name, string $type, bool $isRequired, string $uniqueness, string $mutability = 'readWrite'): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'multiValued' => false,
            'required' => $isRequired,
            'caseExact' => false,
            'mutability' => $mutability,
            'returned' => 'default',
            'uniqueness' => $uniqueness,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $subAttributes
     * @return array<string, mixed>
     */
    private function complexAttribute(string $name, array $subAttributes, bool $isMultiValued = false, string $mutability = 'readWrite'): array
    {
        return [
            'name' => $name,
            'type' => 'complex',
            'multiValued' => $isMultiValued,
            'required' => false,
            'mutability' => $mutability,
            'returned' => 'default',
            'subAttributes' => $subAttributes,
        ];
    }
}
