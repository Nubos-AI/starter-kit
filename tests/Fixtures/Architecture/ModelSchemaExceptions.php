<?php

declare(strict_types=1);

namespace Tests\Fixtures\Architecture;

class ModelSchemaExceptions
{
    /** @var array<string, array<string, string>> */
    public static array $unfillableColumns = [
        'App\\Models\\CustomRecord' => [
            'search_text' => 'derived from the record data and written by a query update in RecordSearchText',
        ],
        'App\\Models\\ImportJob' => [
            'processed_rows' => 'import progress advanced only through forceFill by ProcessImportChunkAction',
        ],
        'App\\Models\\OutboxEvent' => [
            'relay_batch_id' => 'claimed by OutboxRelay through a query increment, never mass assigned',
        ],
        'App\\Models\\PermissionOverride' => [
            'model_type' => 'morph key set by the permissionOverrides relation',
            'model_id' => 'morph key set by the permissionOverrides relation',
        ],
        'App\\Models\\PushSubscription' => [
            'subscribable_type' => 'morph key set by the pushSubscriptions relation',
            'subscribable_id' => 'morph key set by the pushSubscriptions relation',
        ],
        'App\\Models\\RoleAssignment' => [
            'model_type' => 'morph key set by the roleAssignments relation',
            'model_id' => 'morph key set by the roleAssignments relation',
        ],
        'App\\Models\\Team' => [
            'ancestor_team_ids' => 'materialized team tree written only by TeamTreeMaterializer',
            'descendant_team_ids' => 'materialized team tree written only by TeamTreeMaterializer',
        ],
        'App\\Models\\User' => [
            'email_verified_at' => 'verification state set only by the verification flows through forceFill',
            'two_factor_secret' => 'two-factor secret must never be mass assignable',
            'two_factor_recovery_codes' => 'two-factor recovery codes must never be mass assignable',
            'two_factor_confirmed_at' => 'two-factor confirmation set only by Fortify',
            'remember_token' => 'session token must never be mass assignable',
        ],
    ];

    /** @var array<string, string> */
    public static array $unscopedTenantModels = [
        'App\\Models\\User' => 'a user is resolved before a tenant is bound and has no TenantScope by project rule',
        'App\\Models\\OutboxEvent' => 'the outbox relay claims events across every tenant',
        'App\\Models\\TenantSetting' => 'one row per tenant, always read explicitly through forTenant',
        'App\\Models\\IdempotencyKey' => 'resolved explicitly by tenant and access token in EnforceIdempotencyKey',
        'Nubos\\Admin\\Models\\CustomerTenant' => 'links a customer to tenants across tenant boundaries',
        'Nubos\\Automations\\Models\\AutomationTemplate' => 'global templates without a tenant, narrowed by the visibleTo scope',
    ];
}
