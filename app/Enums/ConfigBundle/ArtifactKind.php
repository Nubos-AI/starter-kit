<?php

declare(strict_types=1);

namespace App\Enums\ConfigBundle;

enum ArtifactKind: string
{
    case ObjectTypes = 'object-types';

    case FieldGroups = 'field-groups';

    case FieldDefinitions = 'field-definitions';

    case RelationshipTypes = 'relationship-types';

    case Pipelines = 'pipelines';

    case PipelineStages = 'pipeline-stages';

    case StageTransitions = 'stage-transitions';

    case TransitionGates = 'transition-gates';

    case FieldDependencies = 'field-dependencies';

    case MergeRules = 'merge-rules';

    case ReminderTypes = 'reminder-types';

    case Roles = 'roles';

    case RolePermissions = 'role-permissions';

    case FieldPermissions = 'field-permissions';

    case TeamRecordAccessRules = 'team-record-access-rules';

    case Automations = 'automations';

    case AutomationTemplates = 'automation-templates';

    case NotificationRules = 'notification-rules';

    case NotificationTypeDefaults = 'notification-type-defaults';

    case WebhookSubscriptions = 'webhook-subscriptions';

    case Reports = 'reports';

    case Dashboards = 'dashboards';

    case DashboardWidgets = 'dashboard-widgets';

    case Goals = 'goals';

    case Segments = 'segments';

    case DocumentTemplates = 'document-templates';

    case ExportFieldPresets = 'export-field-presets';

    case ImportMappingPresets = 'import-mapping-presets';

    case AgingRules = 'aging-rules';

    case Skills = 'skills';

    public function identifierFor(string $key): string
    {
        return "{$this->value}:{$key}";
    }
}
