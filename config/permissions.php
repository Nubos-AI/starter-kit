<?php

declare(strict_types=1);

return [
    'tabs' => [
        'members' => ['label' => 'i18n.backend.config.permissions.users', 'type' => 'dropdown'],
        'organization' => ['label' => 'i18n.backend.config.permissions.organisation', 'type' => 'direct'],
        'permissions' => ['label' => 'i18n.backend.config.permissions.permissions', 'type' => 'direct'],
        'roles' => ['label' => 'i18n.backend.config.permissions.roles', 'type' => 'direct'],
        'structure' => ['label' => 'i18n.backend.config.permissions.structure', 'type' => 'dropdown'],
        'data-model' => ['label' => 'i18n.backend.config.permissions.data_model', 'type' => 'dropdown'],
        'integrations' => ['label' => 'i18n.backend.config.permissions.integrations', 'type' => 'dropdown'],
        'governance' => ['label' => 'i18n.backend.config.permissions.processes', 'type' => 'dropdown'],
        'operations' => ['label' => 'i18n.backend.config.permissions.operations', 'type' => 'dropdown'],
        'records' => ['label' => 'i18n.backend.config.permissions.records', 'type' => 'dropdown'],
    ],

    'groups' => [
        'members' => ['tab' => 'members', 'label' => 'i18n.backend.config.permissions.users', 'actions' => ['view', 'invite', 'update', 'password', 'block', 'remove']],
        'absences' => ['tab' => 'members', 'label' => 'i18n.backend.config.permissions.absences', 'actions' => ['view', 'manage']],
        'organisation' => ['tab' => 'organization', 'label' => 'i18n.backend.config.permissions.organisation', 'actions' => ['view', 'update']],
        'permissions' => ['tab' => 'permissions', 'label' => 'i18n.backend.config.permissions.permissions', 'actions' => ['view']],
        'roles' => ['tab' => 'roles', 'label' => 'i18n.backend.config.permissions.roles', 'actions' => ['view', 'create', 'update', 'delete']],
        'teams' => ['tab' => 'structure', 'label' => 'i18n.backend.config.permissions.team', 'actions' => ['view', 'create', 'update', 'delete', 'manage', 'reparent']],
        'tenants' => ['tab' => 'structure', 'label' => 'i18n.backend.config.permissions.tenant', 'actions' => ['view', 'create', 'update', 'delete', 'manage']],
        'object-types' => ['tab' => 'data-model', 'label' => 'i18n.backend.config.permissions.object_types', 'actions' => ['view', 'create', 'update', 'delete']],
        'reminder-types' => ['tab' => 'data-model', 'label' => 'i18n.backend.config.permissions.reminder_types', 'actions' => ['view', 'create', 'update', 'delete']],
        'activity-types' => ['tab' => 'data-model', 'label' => 'i18n.backend.config.permissions.activity_types', 'actions' => ['view', 'create', 'update', 'delete']],
        'skills' => ['tab' => 'data-model', 'label' => 'i18n.backend.config.permissions.skills', 'actions' => ['view', 'create', 'update', 'delete']],
        'api-tokens' => ['tab' => 'integrations', 'label' => 'i18n.backend.config.permissions.api_token', 'actions' => ['manage']],
        'approvals' => ['tab' => 'governance', 'label' => 'i18n.backend.config.permissions.approvals', 'actions' => ['view', 'decide', 'configure']],
        'quality-gates' => ['tab' => 'governance', 'label' => 'i18n.backend.config.permissions.quality_gates', 'actions' => ['configure']],
        'routing' => ['tab' => 'governance', 'label' => 'i18n.backend.config.permissions.assignment_rules', 'actions' => ['configure']],
        'promotions' => ['tab' => 'operations', 'label' => 'i18n.backend.config.permissions.transfers', 'actions' => ['execute', 'approve']],
        'config' => ['tab' => 'operations', 'label' => 'i18n.backend.config.permissions.configuration', 'actions' => ['export', 'import']],
        'maintenance' => ['tab' => 'operations', 'label' => 'i18n.backend.config.permissions.maintenance_mode', 'actions' => ['manage']],
    ],

    'tenant_roles' => [
        'owner' => ['name' => 'owner'],
        'admin' => ['name' => 'admin', 'excluded_groups' => ['tenants']],
        'member' => ['name' => 'member', 'permissions' => ['organisation.view', 'members.view', 'teams.view']],
    ],
];
