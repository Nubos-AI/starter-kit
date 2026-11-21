<?php

declare(strict_types=1);

namespace App\Enums\ConfigBundle;

enum ArtifactKind: string
{
    case ObjectTypes = 'object-types';

    case FieldGroups = 'field-groups';

    case FieldDefinitions = 'field-definitions';

    case RelationshipTypes = 'relationship-types';

    case FieldDependencies = 'field-dependencies';

    case MergeRules = 'merge-rules';

    case ReminderTypes = 'reminder-types';

    case Roles = 'roles';

    case RolePermissions = 'role-permissions';

    case FieldPermissions = 'field-permissions';

    case TeamRecordAccessRules = 'team-record-access-rules';

    case NotificationRules = 'notification-rules';

    case NotificationTypeDefaults = 'notification-type-defaults';

    case WebhookSubscriptions = 'webhook-subscriptions';

    case Reports = 'reports';

    case Dashboards = 'dashboards';

    case DashboardWidgets = 'dashboard-widgets';

    case Goals = 'goals';

    case Segments = 'segments';

    case ExportFieldPresets = 'export-field-presets';

    case ImportMappingPresets = 'import-mapping-presets';

    case AgingRules = 'aging-rules';

    case Skills = 'skills';

    public function label(): string
    {
        return match ($this) {
            self::ObjectTypes => __('i18n.backend.enums.config_bundle.artifact_kind.object_types'),
            self::FieldGroups => __('i18n.backend.enums.config_bundle.artifact_kind.field_groups'),
            self::FieldDefinitions => __('i18n.backend.enums.config_bundle.artifact_kind.field_definitions'),
            self::RelationshipTypes => __('i18n.backend.enums.config_bundle.artifact_kind.relationship_types'),
            self::FieldDependencies => __('i18n.backend.enums.config_bundle.artifact_kind.field_dependencies'),
            self::MergeRules => __('i18n.backend.enums.config_bundle.artifact_kind.merge_rules'),
            self::ReminderTypes => __('i18n.backend.enums.config_bundle.artifact_kind.reminder_types'),
            self::Roles => __('i18n.backend.enums.config_bundle.artifact_kind.roles'),
            self::RolePermissions => __('i18n.backend.enums.config_bundle.artifact_kind.role_permissions'),
            self::FieldPermissions => __('i18n.backend.enums.config_bundle.artifact_kind.field_permissions'),
            self::TeamRecordAccessRules => __('i18n.backend.enums.config_bundle.artifact_kind.team_record_access_rules'),
            self::NotificationRules => __('i18n.backend.enums.config_bundle.artifact_kind.notification_rules'),
            self::NotificationTypeDefaults => __('i18n.backend.enums.config_bundle.artifact_kind.notification_type_defaults'),
            self::WebhookSubscriptions => __('i18n.backend.enums.config_bundle.artifact_kind.webhook_subscriptions'),
            self::Reports => __('i18n.backend.enums.config_bundle.artifact_kind.reports'),
            self::Dashboards => __('i18n.backend.enums.config_bundle.artifact_kind.dashboards'),
            self::DashboardWidgets => __('i18n.backend.enums.config_bundle.artifact_kind.dashboard_widgets'),
            self::Goals => __('i18n.backend.enums.config_bundle.artifact_kind.goals'),
            self::Segments => __('i18n.backend.enums.config_bundle.artifact_kind.segments'),
            self::ExportFieldPresets => __('i18n.backend.enums.config_bundle.artifact_kind.export_field_presets'),
            self::ImportMappingPresets => __('i18n.backend.enums.config_bundle.artifact_kind.import_mapping_presets'),
            self::AgingRules => __('i18n.backend.enums.config_bundle.artifact_kind.aging_rules'),
            self::Skills => __('i18n.backend.enums.config_bundle.artifact_kind.skills'),
        };
    }

    public function singularLabel(): string
    {
        return match ($this) {
            self::ObjectTypes => __('i18n.backend.enums.config_bundle.artifact_kind_singular.object_types'),
            self::FieldGroups => __('i18n.backend.enums.config_bundle.artifact_kind_singular.field_groups'),
            self::FieldDefinitions => __('i18n.backend.enums.config_bundle.artifact_kind_singular.field_definitions'),
            self::RelationshipTypes => __('i18n.backend.enums.config_bundle.artifact_kind_singular.relationship_types'),
            self::FieldDependencies => __('i18n.backend.enums.config_bundle.artifact_kind_singular.field_dependencies'),
            self::MergeRules => __('i18n.backend.enums.config_bundle.artifact_kind_singular.merge_rules'),
            self::ReminderTypes => __('i18n.backend.enums.config_bundle.artifact_kind_singular.reminder_types'),
            self::Roles => __('i18n.backend.enums.config_bundle.artifact_kind_singular.roles'),
            self::RolePermissions => __('i18n.backend.enums.config_bundle.artifact_kind_singular.role_permissions'),
            self::FieldPermissions => __('i18n.backend.enums.config_bundle.artifact_kind_singular.field_permissions'),
            self::TeamRecordAccessRules => __('i18n.backend.enums.config_bundle.artifact_kind_singular.team_record_access_rules'),
            self::NotificationRules => __('i18n.backend.enums.config_bundle.artifact_kind_singular.notification_rules'),
            self::NotificationTypeDefaults => __('i18n.backend.enums.config_bundle.artifact_kind_singular.notification_type_defaults'),
            self::WebhookSubscriptions => __('i18n.backend.enums.config_bundle.artifact_kind_singular.webhook_subscriptions'),
            self::Reports => __('i18n.backend.enums.config_bundle.artifact_kind_singular.reports'),
            self::Dashboards => __('i18n.backend.enums.config_bundle.artifact_kind_singular.dashboards'),
            self::DashboardWidgets => __('i18n.backend.enums.config_bundle.artifact_kind_singular.dashboard_widgets'),
            self::Goals => __('i18n.backend.enums.config_bundle.artifact_kind_singular.goals'),
            self::Segments => __('i18n.backend.enums.config_bundle.artifact_kind_singular.segments'),
            self::ExportFieldPresets => __('i18n.backend.enums.config_bundle.artifact_kind_singular.export_field_presets'),
            self::ImportMappingPresets => __('i18n.backend.enums.config_bundle.artifact_kind_singular.import_mapping_presets'),
            self::AgingRules => __('i18n.backend.enums.config_bundle.artifact_kind_singular.aging_rules'),
            self::Skills => __('i18n.backend.enums.config_bundle.artifact_kind_singular.skills'),
        };
    }
}
