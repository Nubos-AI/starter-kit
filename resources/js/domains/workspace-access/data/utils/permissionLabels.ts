import { sharedModuleOptions } from '@/shared/lib/modules';

type Translate = (key: string) => string;

const ACTION_LABELS: Record<string, string> = {
  view: 'i18n.lib.permission_labels.actions.view',
  create: 'i18n.lib.permission_labels.actions.create',
  update: 'i18n.lib.permission_labels.actions.update',
  delete: 'i18n.lib.permission_labels.actions.delete',
  import: 'i18n.lib.permission_labels.actions.import',
  export: 'i18n.lib.permission_labels.actions.export',
  invite: 'i18n.lib.permission_labels.actions.invite',
  remove: 'i18n.lib.permission_labels.actions.remove',
  manage: 'i18n.lib.permission_labels.actions.manage',
  password: 'i18n.lib.permission_labels.actions.password',
  block: 'i18n.lib.permission_labels.actions.block',
  configure: 'i18n.lib.permission_labels.actions.configure',
  decide: 'i18n.lib.permission_labels.actions.decide',
  reparent: 'i18n.lib.permission_labels.actions.reparent',
  reset: 'i18n.lib.permission_labels.actions.reset',
  execute: 'i18n.lib.permission_labels.actions.execute',
  approve: 'i18n.lib.permission_labels.actions.approve',
  'watchers.manage': 'i18n.lib.permission_labels.actions.watchers_manage',
  'rules.manage': 'i18n.lib.permission_labels.actions.rules_manage',
  'audit.view': 'i18n.lib.permission_labels.actions.audit_view',
};

const GROUP_LABELS: Record<string, string> = {
  members: 'i18n.lib.permission_labels.groups.members',
  absences: 'i18n.lib.permission_labels.groups.absences',
  organisation: 'i18n.lib.permission_labels.groups.organisation',
  permissions: 'i18n.lib.permission_labels.groups.permissions',
  roles: 'i18n.lib.permission_labels.groups.roles',
  teams: 'i18n.lib.permission_labels.groups.teams',
  tenants: 'i18n.lib.permission_labels.groups.tenants',
  'object-types': 'i18n.lib.permission_labels.groups.object_types',
  'reminder-types': 'i18n.lib.permission_labels.groups.reminder_types',
  'activity-types': 'i18n.lib.permission_labels.groups.activity_types',
  skills: 'i18n.lib.permission_labels.groups.skills',
  'api-tokens': 'i18n.lib.permission_labels.groups.api_tokens',
  sso: 'i18n.lib.permission_labels.groups.sso',
  approvals: 'i18n.lib.permission_labels.groups.approvals',
  'quality-gates': 'i18n.lib.permission_labels.groups.quality_gates',
  routing: 'i18n.lib.permission_labels.groups.routing',
  promotions: 'i18n.lib.permission_labels.groups.promotions',
  config: 'i18n.lib.permission_labels.groups.config',
  maintenance: 'i18n.lib.permission_labels.groups.maintenance',
};

function headline(value: string): string {
  return value
    .split(/[.\-_]/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

export function permissionGroupLabel(groupKey: string, t: Translate): string {
  const configured = GROUP_LABELS[groupKey];

  if (configured !== undefined) {
    return t(configured);
  }

  return (
    sharedModuleOptions('permissions.groups').find((option) => option.value === groupKey)?.label ?? headline(groupKey)
  );
}

export function permissionActionLabel(name: string, groupKey: string, t: Translate): string {
  const prefix = `${groupKey}.`;
  const action = name.startsWith(prefix) ? name.slice(prefix.length) : name;
  const configured = ACTION_LABELS[action];

  return (
    sharedModuleOptions('permissions.actions').find((option) => option.value === name)?.label ??
    (configured === undefined ? undefined : t(configured)) ??
    headline(action)
  );
}
