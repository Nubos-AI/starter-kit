<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\RecordBackfillKind;
use App\Handlers\CustomFields\BooleanHandler;
use App\Handlers\CustomFields\ComputedFieldHandler;
use App\Handlers\CustomFields\DateHandler;
use App\Handlers\CustomFields\DateTimeHandler;
use App\Handlers\CustomFields\DecimalHandler;
use App\Handlers\CustomFields\EmailHandler;
use App\Handlers\CustomFields\FileFieldHandler;
use App\Handlers\CustomFields\GeoAddressHandler;
use App\Handlers\CustomFields\MoneyHandler;
use App\Handlers\CustomFields\MultiSelectHandler;
use App\Handlers\CustomFields\NumberHandler;
use App\Handlers\CustomFields\PhoneHandler;
use App\Handlers\CustomFields\RelationFieldHandler;
use App\Handlers\CustomFields\RollupFieldHandler;
use App\Handlers\CustomFields\SingleSelectHandler;
use App\Handlers\CustomFields\TextLongHandler;
use App\Handlers\CustomFields\TextShortHandler;
use App\Handlers\CustomFields\UrlHandler;
use App\Handlers\Engine\Merge\ConcatenateHandler;
use App\Handlers\Engine\Merge\ManualHandler;
use App\Handlers\Engine\Merge\MaxHandler;
use App\Handlers\Engine\Merge\MinHandler;
use App\Handlers\Engine\Merge\PreferNewestHandler;
use App\Handlers\Engine\Merge\PreferNonEmptyHandler;
use App\Handlers\Engine\Merge\PreferOldestHandler;
use App\Handlers\Engine\Merge\PreferSourceHandler;
use App\Handlers\Engine\Merge\PreferTargetHandler;
use App\Handlers\Engine\Merge\SumHandler;
use App\Handlers\Engine\Merge\UnionHandler;
use App\Handlers\Timeline\TimelineProjectionBackfillStrategy;
use App\Models\AgingRule;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\ExportFieldPreset;
use App\Models\FieldDefinition;
use App\Models\FieldDependency;
use App\Models\FieldGroup;
use App\Models\FieldPermission;
use App\Models\Goal;
use App\Models\GoalPeriod;
use App\Models\ImportMappingPreset;
use App\Models\MergeRule;
use App\Models\NotificationRule;
use App\Models\NotificationTypeDefault;
use App\Models\ObjectType;
use App\Models\Permission;
use App\Models\PermissionOverride;
use App\Models\RelationshipType;
use App\Models\ReminderType;
use App\Models\Report;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Segment;
use App\Models\Skill;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookSubscription;

$userAndTeamHolders = [
    'model' => [
        User::class => 'users',
        Team::class => 'teams',
    ],
    'scope' => [
        Team::class => 'teams',
    ],
];

$tenantScopeBoundary = [
    'scope' => [
        Tenant::class,
    ],
];

return [
    'record_capabilities' => ['records', 'custom_fields', 'relations', 'hierarchy', 'timeline', 'merge', 'import', 'export', 'aging', 'rollups', 'trash', 'bulk_actions', 'lookup'],
    'records_table' => 'custom_records',

    'approvals' => [
        'trigger_forced_anchors' => [],
        'decision_permissions' => [
            'App\\Models\\PromotionRun' => 'promotions.approve',
        ],
    ],

    'record_models' => [],

    'model_paths' => ['app/Models'],

    'native_backings' => [],

    'backfill' => [
        'timeline_batch_size' => (int) env('ENGINE_BACKFILL_TIMELINE_BATCH_SIZE', 25),
        'checkpoint_batches' => (int) env('ENGINE_BACKFILL_CHECKPOINT_BATCHES', 25),
        'max_batch_attempts' => (int) env('ENGINE_BACKFILL_MAX_BATCH_ATTEMPTS', 3),
        'strategies' => [
            RecordBackfillKind::TimelineProjection->value => TimelineProjectionBackfillStrategy::class,
        ],
    ],

    'outbox' => [
        'batch_size' => (int) env('ENGINE_OUTBOX_BATCH_SIZE', 200),
    ],

    'diff' => [
        'redacted_placeholder' => env('ENGINE_DIFF_REDACTED_PLACEHOLDER', '[encrypted]'),
    ],

    'field_type_handlers' => [
        FieldType::TextShort->value => TextShortHandler::class,
        FieldType::TextLong->value => TextLongHandler::class,
        FieldType::Number->value => NumberHandler::class,
        FieldType::Decimal->value => DecimalHandler::class,
        FieldType::Money->value => MoneyHandler::class,
        FieldType::Date->value => DateHandler::class,
        FieldType::DateTime->value => DateTimeHandler::class,
        FieldType::Boolean->value => BooleanHandler::class,
        FieldType::SingleSelect->value => SingleSelectHandler::class,
        FieldType::MultiSelect->value => MultiSelectHandler::class,
        FieldType::RelationHasMany->value => RelationFieldHandler::class,
        FieldType::RelationManyToMany->value => RelationFieldHandler::class,
        FieldType::Email->value => EmailHandler::class,
        FieldType::Phone->value => PhoneHandler::class,
        FieldType::Url->value => UrlHandler::class,
        FieldType::File->value => FileFieldHandler::class,
        FieldType::GeoAddress->value => GeoAddressHandler::class,
        FieldType::Rollup->value => RollupFieldHandler::class,
        FieldType::Computed->value => ComputedFieldHandler::class,
    ],

    'rollups' => [
        'max_iterations' => (int) env('ENGINE_ROLLUP_MAX_ITERATIONS', 1000),
        'max_computed_fields_per_pass' => (int) env('ENGINE_ROLLUP_MAX_COMPUTED_FIELDS_PER_PASS', 200),
        'debounce_seconds' => (int) env('ENGINE_ROLLUP_DEBOUNCE_SECONDS', 5),
        'unique_lock_seconds' => (int) env('ENGINE_ROLLUP_UNIQUE_LOCK_SECONDS', 30),
    ],

    'search' => [
        'minimum_term_length' => (int) env('ENGINE_SEARCH_MINIMUM_TERM_LENGTH', 3),
        'max_fields' => (int) env('ENGINE_SEARCH_MAX_FIELDS', 12),
    ],

    'relations' => [
        'max_entries' => (int) env('ENGINE_RELATIONS_MAX_ENTRIES', 50),
        'max_block_size' => (int) env('ENGINE_RELATIONS_MAX_BLOCK_SIZE', 200),
    ],

    'hierarchy' => [
        'max_traversal_depth' => (int) env('ENGINE_HIERARCHY_MAX_TRAVERSAL_DEPTH', 1000),
        'max_result_rows' => (int) env('ENGINE_HIERARCHY_MAX_RESULT_ROWS', 5000),
        'max_tree_sort_rows' => (int) env('ENGINE_HIERARCHY_MAX_TREE_SORT_ROWS', 5000),
    ],

    'attachments' => [
        'max_size_kb' => (int) env('ENGINE_ATTACHMENT_MAX_SIZE_KB', 10240),
        'allowed_mimes' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'text/plain',
            'text/html',
            'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],

    'validation' => [
        'max_regex_length' => (int) env('ENGINE_VALIDATION_MAX_REGEX_LENGTH', 512),
    ],

    'field_type_change' => [
        'example_limit' => (int) env('ENGINE_FIELD_TYPE_CHANGE_EXAMPLE_LIMIT', 5),
    ],

    'merge' => [
        'undo_window_days' => (int) env('ENGINE_MERGE_UNDO_WINDOW_DAYS', 30),
        'strategies' => [
            MergeFieldStrategy::PreferNonEmpty->value => PreferNonEmptyHandler::class,
            MergeFieldStrategy::PreferTarget->value => PreferTargetHandler::class,
            MergeFieldStrategy::PreferSource->value => PreferSourceHandler::class,
            MergeFieldStrategy::PreferNewest->value => PreferNewestHandler::class,
            MergeFieldStrategy::PreferOldest->value => PreferOldestHandler::class,
            MergeFieldStrategy::Concatenate->value => ConcatenateHandler::class,
            MergeFieldStrategy::Union->value => UnionHandler::class,
            MergeFieldStrategy::Sum->value => SumHandler::class,
            MergeFieldStrategy::Max->value => MaxHandler::class,
            MergeFieldStrategy::Min->value => MinHandler::class,
            MergeFieldStrategy::Manual->value => ManualHandler::class,
        ],
    ],

    'trash' => [
        'purge_cron' => (string) env('ENGINE_TRASH_PURGE_CRON', '30 0 * * *'),
        'purge_timezone' => (string) env('ENGINE_TRASH_PURGE_TIMEZONE', 'Europe/Berlin'),
        'purge_activity_timeout' => (int) env('ENGINE_TRASH_PURGE_ACTIVITY_TIMEOUT', 300),
    ],

    'reserved_slugs' => [
        'system',
        'nubos',
        'admin',
        'api',
        'reports',
        'dashboards',
        'goals',
        'approvals',
        'quality-gates',
        'routing',
        'skills',
        'absences',
        'members',
        'organisation',
        'permissions',
        'roles',
        'teams',
        'tenants',
        'object-types',
        'reminder-types',
        'activity-types',
        'api-tokens',
        'promotions',
        'config',
        'maintenance',
    ],

    'definition_snapshot' => [
        'object_type_keys' => [
            'key',
            'slug',
            'name',
            'is_system',
            'storage_strategy',
            'is_navigable',
            'nav_icon',
            'nav_position',
            'record_number_format',
            'dedup_keys',
            'business_key_prefix',
            'requires_deletion_reason',
            'retention_days',
            'hierarchy_relationship_type_id',
        ],
        'field_keys' => [
            'field_group_id',
            'key',
            'field_type',
            'is_required',
            'is_unique',
            'is_searchable',
            'is_translatable',
            'is_encrypted',
            'is_sortable',
            'is_filterable',
            'is_default_column',
            'list_position',
            'merge_strategy',
            'config',
            'validation_rules',
            'default_value',
            'i18n_labels',
            'i18n_descriptions',
        ],
        'object_type_restorable' => [
            'name',
            'is_navigable',
            'nav_icon',
            'nav_position',
            'record_number_format',
            'storage_strategy',
            'dedup_keys',
            'business_key_prefix',
            'requires_deletion_reason',
            'retention_days',
        ],
        'object_type_never_restorable' => [
            'key',
            'slug',
            'is_system',
            'hierarchy_relationship_type_id',
        ],
        'storage' => [
            'object-types' => 'object_type_versions',
            'field-groups' => 'configuration_artifact_versions',
            'field-definitions' => 'field_definition_versions',
            'relationship-types' => 'configuration_artifact_versions',
            'field-dependencies' => 'configuration_artifact_versions',
            'merge-rules' => 'configuration_artifact_versions',
            'reminder-types' => 'configuration_artifact_versions',
            'roles' => 'configuration_artifact_versions',
            'role-permissions' => 'configuration_artifact_versions',
            'field-permissions' => 'configuration_artifact_versions',
            'team-record-access-rules' => 'configuration_artifact_versions',
            'notification-rules' => 'configuration_artifact_versions',
            'notification-type-defaults' => 'configuration_artifact_versions',
            'webhook-subscriptions' => 'configuration_artifact_versions',
            'reports' => 'configuration_artifact_versions',
            'dashboards' => 'configuration_artifact_versions',
            'dashboard-widgets' => 'configuration_artifact_versions',
            'goals' => 'configuration_artifact_versions',
            'segments' => 'configuration_artifact_versions',
            'export-field-presets' => 'configuration_artifact_versions',
            'import-mapping-presets' => 'configuration_artifact_versions',
            'aging-rules' => 'configuration_artifact_versions',
            'skills' => 'configuration_artifact_versions',
        ],
    ],

    'config_bundle' => [
        'placeholder' => '__PLACEHOLDER__',

        'manifest_file' => 'manifest.yaml',

        'writers' => [
            'App\Handlers\ConfigBundle\ObjectModelArtifactWriter',
            'App\Handlers\ConfigBundle\AuthorizationArtifactWriter',
            'App\Handlers\ConfigBundle\CommunicationArtifactWriter',
            'App\Handlers\ConfigBundle\AnalyticsArtifactWriter',
        ],

        'reserved_tokens' => [
            'absent_reference' => 'none',
        ],

        'key_source_tables' => [
            'teams',
            'permissions',
        ],

        'non_portable_markers' => [
            'user_id',
            'team_id',
            'record_id',
            'assignee_id',
            'owner_id',
            'created_by_id',
        ],

        'fields' => [
            'object-types' => [
                'key',
                'hierarchy_relationship_type_id',
                'slug',
                'business_key_prefix',
                'is_system',
                'is_navigable',
                'requires_deletion_reason',
                'storage_strategy',
                'name',
                'nav_icon',
                'nav_position',
                'record_number_format',
                'retention_days',
                'dedup_keys',
            ],
            'field-groups' => [
                'key',
                'position',
                'i18n_labels',
                'i18n_descriptions',
            ],
            'field-definitions' => [
                'key',
                'field_group_id',
                'field_type',
                'is_required',
                'is_unique',
                'is_searchable',
                'is_translatable',
                'is_encrypted',
                'is_sortable',
                'is_filterable',
                'is_default_column',
                'list_position',
                'merge_strategy',
                'config',
                'validation_rules',
                'default_value',
                'i18n_labels',
                'i18n_descriptions',
            ],
            'relationship-types' => [
                'key',
                'from_object_type_id',
                'to_object_type_id',
                'inverse_key',
                'is_required',
                'is_hierarchy',
                'cardinality',
                'cascade_behavior',
                'name',
                'inverse_name',
            ],
            'field-dependencies' => [
                'key',
                'relationship_type_id',
            ],
            'merge-rules' => [
                'key',
                'is_active',
                'mode',
                'position',
                'deny_reason',
                'condition',
                'field_strategies',
                'transfer_policy',
                'options',
            ],
            'reminder-types' => [
                'key',
            ],
            'roles' => [
                'key',
                'is_system',
                'grants_subteam_visibility',
                'authority',
            ],
            'role-permissions' => [
                'key',
            ],
            'field-permissions' => [
                'key',
                'can_read',
                'can_write',
            ],
            'team-record-access-rules' => [
                'key',
                'is_active',
                'inheritance',
                'filter_definition',
            ],
            'notification-rules' => [
                'key',
                'segment_id',
                'is_active',
                'config',
                'filter_definition',
                'action',
            ],
            'notification-type-defaults' => [
                'key',
                'enabled',
                'delivery_mode',
            ],
            'webhook-subscriptions' => [
                'key',
                'object_type_id',
                'role_id',
                'target_url',
                'event_types',
            ],
            'reports' => [
                'key',
                'description',
                'aggregation_type',
                'aggregation_field_key',
                'group_by_field_key',
                'group_by_bucket',
                'series_field_key',
                'chart_type',
                'execution_mode',
                'filter_definition',
            ],
            'dashboards' => [
                'key',
                'is_tenant_wide',
                'description',
            ],
            'dashboard-widgets' => [
                'key',
                'report_id',
                'goal_id',
                'title',
                'chart_type',
                'position',
                'column_span',
                'definition',
            ],
            'goals' => [
                'key',
                'includes_subteams',
                'scope_type',
                'scope_field_key',
                'period_type',
                'period_field_key',
                'direction',
                'target_value',
            ],
            'segments' => [
                'key',
                'is_system',
                'is_default',
                'i18n_labels',
                'filter_definition',
            ],

            'export-field-presets' => [
                'key',
                'fields',
            ],
            'import-mapping-presets' => [
                'key',
                'mapping',
            ],
            'aging-rules' => [
                'key',
                'is_active',
                'triggers_automation',
                'clock',
                'clock_field_key',
                'condition',
                'thresholds',
            ],
            'skills' => [
                'key',
            ],
        ],
    ],

    'artifact_dependencies' => [
        'object-types' => [
            'requires' => [],
            'optional' => [
                ['kind' => 'relationship-types', 'column' => 'hierarchy_relationship_type_id', 'source' => 'payload', 'field' => 'hierarchy_relationship_type_id'],
            ],
            'exclusive' => [],
        ],
        'field-groups' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'field-definitions' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [
                ['kind' => 'field-groups', 'column' => 'field_group_id', 'source' => 'payload', 'field' => 'field_group_id'],
            ],
            'exclusive' => [],
        ],
        'relationship-types' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'from_object_type_id', 'source' => 'payload', 'field' => 'from_object_type_id'],
                ['kind' => 'object-types', 'column' => 'to_object_type_id', 'source' => 'payload', 'field' => 'to_object_type_id'],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'field-dependencies' => [
            'requires' => [
                ['kind' => 'field-definitions', 'column' => 'rollup_field_id', 'source' => 'key', 'derivation' => 'slice', 'offset' => 0, 'length' => 2],
                ['kind' => 'field-definitions', 'column' => 'depends_on_field_id', 'source' => 'key', 'derivation' => 'slice', 'offset' => 2, 'length' => null],
            ],
            'optional' => [
                ['kind' => 'relationship-types', 'column' => 'relationship_type_id', 'source' => 'payload', 'field' => 'relationship_type_id'],
            ],
            'exclusive' => [],
        ],
        'merge-rules' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'reminder-types' => [
            'requires' => [],
            'optional' => [],
            'exclusive' => [],
        ],
        'roles' => [
            'requires' => [],
            'optional' => [],
            'exclusive' => [],
        ],
        'role-permissions' => [
            'requires' => [
                ['kind' => 'roles', 'column' => 'role_id', 'source' => 'key', 'derivation' => 'slice', 'offset' => 0, 'length' => 2],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'field-permissions' => [
            'requires' => [
                ['kind' => 'roles', 'column' => 'role_id', 'source' => 'key', 'derivation' => 'slice', 'offset' => 0, 'length' => 2],
                ['kind' => 'field-definitions', 'column' => 'field_definition_id', 'source' => 'key', 'derivation' => 'slice', 'offset' => 2, 'length' => null],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'team-record-access-rules' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'slice', 'offset' => 1, 'length' => null],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'notification-rules' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -2],
            ],
            'optional' => [
                ['kind' => 'segments', 'column' => 'segment_id', 'source' => 'payload', 'field' => 'segment_id'],
            ],
            'exclusive' => [],
        ],
        'notification-type-defaults' => [
            'requires' => [],
            'optional' => [],
            'exclusive' => [],
        ],
        'webhook-subscriptions' => [
            'requires' => [],
            'optional' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'payload', 'field' => 'object_type_id'],
                ['kind' => 'roles', 'column' => 'role_id', 'source' => 'payload', 'field' => 'role_id'],
            ],
            'exclusive' => [],
        ],
        'reports' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'dashboards' => [
            'requires' => [],
            'optional' => [],
            'exclusive' => [],
        ],
        'dashboard-widgets' => [
            'requires' => [
                ['kind' => 'dashboards', 'column' => 'dashboard_id', 'source' => 'key', 'derivation' => 'slice', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [
                [
                    'constraint' => 'chk_dashboard_widgets_source_exclusive',
                    'members' => [
                        ['column' => 'report_id', 'kind' => 'reports'],
                        ['column' => 'definition', 'kind' => null],
                        ['column' => 'goal_id', 'kind' => 'goals'],
                    ],
                ],
            ],
        ],
        'goals' => [
            'requires' => [
                ['kind' => 'reports', 'column' => 'report_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'segments' => [
            'requires' => [],
            'optional' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'exclusive' => [],
        ],

        'export-field-presets' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'import-mapping-presets' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'aging-rules' => [
            'requires' => [
                ['kind' => 'object-types', 'column' => 'object_type_id', 'source' => 'key', 'derivation' => 'prefix', 'offset' => 0, 'length' => -1],
            ],
            'optional' => [],
            'exclusive' => [],
        ],
        'skills' => [
            'requires' => [],
            'optional' => [],
            'exclusive' => [],
        ],
    ],

    'tenant_artifacts' => [
        [
            'table' => 'users',
            'model' => User::class,
            'scope' => 'tenant_owned_only',
            'bundle' => false,
            'clone' => true,
            'remap' => [
                'current_team_id' => 'teams',
                'default_dashboard_id' => 'dashboards',
                'invited_by_id' => 'users',
            ],
            'deferred_remap' => [
                'current_team_id',
                'default_dashboard_id',
                'invited_by_id',
            ],
            'rewrite_columns' => [
                'email',
            ],
            'secret_columns' => [
                'password',
                'invitation_token_hash',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'remember_token',
            ],
        ],
        [
            'table' => 'teams',
            'model' => Team::class,
            'scope' => 'direct',
            'bundle' => false,
            'clone' => true,
            'remap' => [
                'parent_team_id' => 'teams',
                'owner_id' => 'users',
            ],
            'deferred_remap' => [
                'parent_team_id',
            ],
        ],
        [
            'table' => 'team_user',
            'model' => null,
            'scope' => 'via',
            'via_column' => 'team_id',
            'bundle' => false,
            'clone' => true,
            'remap' => [
                'team_id' => 'teams',
                'user_id' => 'users',
            ],
        ],
        [
            'table' => 'roles',
            'model' => Role::class,
            'scope' => 'direct',
            'kind' => 'roles',
            'bundle' => true,
            'clone' => true,
            'remap' => [],
        ],
        [
            'table' => 'permissions',
            'model' => Permission::class,
            'scope' => 'direct',
            'bundle' => false,
            'clone' => true,
            'remap' => [],
        ],
        [
            'table' => 'role_permission',
            'model' => null,
            'scope' => 'direct',
            'kind' => 'role-permissions',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'role_id' => 'roles',
                'permission_id' => 'permissions',
            ],
        ],
        [
            'table' => 'role_assignments',
            'model' => RoleAssignment::class,
            'scope' => 'via',
            'via_column' => 'role_id',
            'bundle' => false,
            'clone' => true,
            'remap' => [
                'role_id' => 'roles',
            ],
            'morph_remap' => $userAndTeamHolders,
            'morph_boundary' => $tenantScopeBoundary,
        ],
        [
            'table' => 'object_types',
            'model' => ObjectType::class,
            'scope' => 'direct',
            'kind' => 'object-types',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'hierarchy_relationship_type_id' => 'relationship_types',
            ],
            'deferred_remap' => [
                'hierarchy_relationship_type_id',
            ],
        ],
        [
            'table' => 'field_groups',
            'model' => FieldGroup::class,
            'scope' => 'direct',
            'kind' => 'field-groups',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
            ],
        ],
        [
            'table' => 'field_definitions',
            'model' => FieldDefinition::class,
            'scope' => 'direct',
            'kind' => 'field-definitions',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
                'field_group_id' => 'field_groups',
            ],
        ],
        [
            'table' => 'field_permissions',
            'model' => FieldPermission::class,
            'scope' => 'direct',
            'kind' => 'field-permissions',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'field_definition_id' => 'field_definitions',
                'role_id' => 'roles',
            ],
        ],
        [
            'table' => 'relationship_types',
            'model' => RelationshipType::class,
            'scope' => 'direct',
            'kind' => 'relationship-types',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'from_object_type_id' => 'object_types',
                'to_object_type_id' => 'object_types',
            ],
        ],
        [
            'table' => 'field_dependencies',
            'model' => FieldDependency::class,
            'scope' => 'direct',
            'kind' => 'field-dependencies',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'depends_on_field_id' => 'field_definitions',
                'rollup_field_id' => 'field_definitions',
                'relationship_type_id' => 'relationship_types',
            ],
        ],
        [
            'table' => 'merge_rules',
            'model' => MergeRule::class,
            'scope' => 'direct',
            'kind' => 'merge-rules',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
            ],
        ],
        [
            'table' => 'reminder_types',
            'model' => ReminderType::class,
            'scope' => 'direct',
            'kind' => 'reminder-types',
            'bundle' => true,
            'clone' => true,
            'remap' => [],
        ],
        [
            'table' => 'permission_overrides',
            'model' => PermissionOverride::class,
            'scope' => 'direct',
            'bundle' => false,
            'clone' => true,
            'remap' => [
                'permission_id' => 'permissions',
            ],
            'morph_remap' => $userAndTeamHolders,
            'morph_boundary' => $tenantScopeBoundary,
        ],
        [
            'table' => 'team_record_access_rules',
            'model' => TeamRecordAccessRule::class,
            'scope' => 'direct',
            'kind' => 'team-record-access-rules',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'team_id' => 'teams',
                'object_type_id' => 'object_types',
                'created_by_id' => 'users',
            ],
        ],
        [
            'table' => 'notification_rules',
            'model' => NotificationRule::class,
            'scope' => 'direct',
            'kind' => 'notification-rules',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
                'segment_id' => 'segments',
                'created_by_id' => 'users',
            ],
            'deferred_remap' => [
                'segment_id',
            ],
        ],
        [
            'table' => 'notification_type_defaults',
            'model' => NotificationTypeDefault::class,
            'scope' => 'direct',
            'kind' => 'notification-type-defaults',
            'bundle' => true,
            'clone' => true,
            'remap' => [],
        ],
        [
            'table' => 'webhook_subscriptions',
            'model' => WebhookSubscription::class,
            'scope' => 'direct',
            'kind' => 'webhook-subscriptions',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
                'role_id' => 'roles',
                'service_user_id' => 'users',
            ],
            'secret_columns' => [
                'auth_username',
                'auth_password',
                'secret',
                'secret_previous',
            ],
            'placeholder_columns' => [
                'target_url',
            ],
        ],
        [
            'table' => 'reports',
            'model' => Report::class,
            'scope' => 'direct',
            'kind' => 'reports',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
                'owner_id' => 'users',
            ],
        ],
        [
            'table' => 'dashboards',
            'model' => Dashboard::class,
            'scope' => 'direct',
            'kind' => 'dashboards',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'owner_id' => 'users',
            ],
        ],
        [
            'table' => 'goals',
            'model' => Goal::class,
            'scope' => 'direct',
            'kind' => 'goals',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'report_id' => 'reports',
                'owner_id' => 'users',
                'target_user_id' => 'users',
                'target_team_id' => 'teams',
            ],
        ],
        [
            'table' => 'goal_periods',
            'model' => GoalPeriod::class,
            'scope' => 'direct',
            'bundle' => false,
            'clone' => true,
            'remap' => [
                'goal_id' => 'goals',
            ],
        ],
        [
            'table' => 'dashboard_widgets',
            'model' => DashboardWidget::class,
            'scope' => 'direct',
            'kind' => 'dashboard-widgets',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'dashboard_id' => 'dashboards',
                'report_id' => 'reports',
                'goal_id' => 'goals',
            ],
        ],
        [
            'table' => 'segments',
            'model' => Segment::class,
            'scope' => 'direct',
            'kind' => 'segments',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
                'owner_id' => 'users',
            ],
        ],

        [
            'table' => 'export_field_presets',
            'model' => ExportFieldPreset::class,
            'scope' => 'direct',
            'kind' => 'export-field-presets',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
                'user_id' => 'users',
            ],
        ],
        [
            'table' => 'import_mapping_presets',
            'model' => ImportMappingPreset::class,
            'scope' => 'direct',
            'kind' => 'import-mapping-presets',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
                'user_id' => 'users',
            ],
        ],
        [
            'table' => 'aging_rules',
            'model' => AgingRule::class,
            'scope' => 'via',
            'via_column' => 'object_type_id',
            'kind' => 'aging-rules',
            'bundle' => true,
            'clone' => true,
            'remap' => [
                'object_type_id' => 'object_types',
            ],
        ],
        [
            'table' => 'skills',
            'model' => Skill::class,
            'scope' => 'direct',
            'kind' => 'skills',
            'bundle' => true,
            'clone' => true,
            'remap' => [],
        ],
    ],

    'tenant_content' => [
        ['table' => 'users'],
        ['table' => 'sessions', 'via' => ['column' => 'user_id', 'source' => 'users']],
        ['table' => 'personal_access_tokens', 'via' => ['column' => 'tokenable_id', 'source' => 'users']],
        ['table' => 'password_reset_tokens', 'via' => ['column' => 'email', 'source' => 'users', 'source_column' => 'email']],
        ['table' => 'user_preferences'],
        ['table' => 'user_notification_preferences'],
        ['table' => 'absence_delegations'],
        ['table' => 'teams'],
        ['table' => 'roles'],
        ['table' => 'permissions'],
        ['table' => 'role_permission'],
        ['table' => 'permission_overrides'],
        ['table' => 'skills'],
        ['table' => 'object_types'],
        ['table' => 'field_groups'],
        ['table' => 'field_definitions'],
        ['table' => 'field_permissions'],
        ['table' => 'relationship_types'],
        ['table' => 'field_dependencies'],
        ['table' => 'merge_rules'],
        ['table' => 'team_record_access_rules'],
        ['table' => 'tenant_settings'],
        ['table' => 'record_counters'],
        ['table' => 'custom_records'],
        ['table' => 'record_links'],
        ['table' => 'record_notes'],
        ['table' => 'record_watchers'],
        ['table' => 'record_merges'],
        ['table' => 'timeline_entries'],
        ['table' => 'filter_states'],
        ['table' => 'approval_definitions'],
        ['table' => 'approval_processes'],
        ['table' => 'approval_process_stages'],
        ['table' => 'routing_cursors'],
        ['table' => 'reminder_types'],
        ['table' => 'reminder_tasks'],
        ['table' => 'activity_types'],
        ['table' => 'record_activities'],
        ['table' => 'notification_type_defaults'],
        ['table' => 'notification_rules'],
        ['table' => 'notification_rule_dispatches'],
        ['table' => 'notification_inbox'],
        ['table' => 'notification_digest_state'],
        ['table' => 'push_subscriptions'],
        ['table' => 'webhook_subscriptions'],
        ['table' => 'reports'],
        ['table' => 'dashboards'],
        ['table' => 'goals'],
        ['table' => 'dashboard_widgets'],
        ['table' => 'dashboard_shares'],
        ['table' => 'goal_periods'],
        ['table' => 'segments'],
        ['table' => 'segment_shares'],
        ['table' => 'export_field_presets'],
        ['table' => 'import_mapping_presets'],
        ['table' => 'export_jobs'],
        ['table' => 'import_jobs'],
        ['table' => 'idempotency_keys'],
        ['table' => 'record_backfill_runs'],
        ['table' => 'formula_backfill_runs'],
        ['table' => 'promotion_baselines'],
        ['table' => 'promotion_runs'],
        ['table' => 'configuration_artifact_versions'],
        ['table' => 'maintenance_locks'],
    ],
];
