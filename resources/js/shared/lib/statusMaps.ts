import type { BadgeVariants } from '@/shared/ui/primitives/badge';

export type StatusVariant = NonNullable<BadgeVariants['variant']>;

export interface StatusEntry {
  label: string;
  variant: StatusVariant;
}

export type StatusMap = Record<string, StatusEntry>;

export const IMPORT_JOB_STATUS: StatusMap = {
  pending: {
    label: 'i18n.lib.status_maps.import_job_status.pending',
    variant: 'outline',
  },
  running: {
    label: 'i18n.lib.status_maps.import_job_status.running',
    variant: 'info',
  },
  completed: {
    label: 'i18n.lib.status_maps.import_job_status.completed',
    variant: 'success',
  },
  failed: {
    label: 'i18n.lib.status_maps.import_job_status.failed',
    variant: 'destructive',
  },
};

export const USER_STATUS: StatusMap = {
  invited: {
    label: 'i18n.lib.status_maps.user_status.invited',
    variant: 'info',
  },
  accepted: {
    label: 'i18n.lib.status_maps.user_status.accepted',
    variant: 'success',
  },
  blocked: {
    label: 'i18n.lib.status_maps.user_status.blocked',
    variant: 'destructive',
  },
  deleted: {
    label: 'i18n.lib.status_maps.user_status.deleted',
    variant: 'secondary',
  },
};

export const WEBHOOK_STATUS: StatusMap = {
  pending: {
    label: 'i18n.lib.status_maps.webhook_status.pending',
    variant: 'outline',
  },
  active: {
    label: 'i18n.lib.status_maps.webhook_status.active',
    variant: 'success',
  },
  disabled: {
    label: 'i18n.lib.status_maps.webhook_status.disabled',
    variant: 'destructive',
  },
};

export const REPORT_EXECUTION_MODE: StatusMap = {
  viewer: {
    label: 'i18n.lib.status_maps.report_execution_mode.viewer',
    variant: 'secondary',
  },
  definer: {
    label: 'i18n.lib.status_maps.report_execution_mode.definer',
    variant: 'info',
  },
};

export const DOMAIN_VERIFICATION: StatusMap = {
  pending: {
    label: 'i18n.lib.status_maps.domain_verification.pending',
    variant: 'outline',
  },
  verified: {
    label: 'i18n.lib.status_maps.domain_verification.verified',
    variant: 'success',
  },
};

export const DASHBOARD_VISIBILITY: StatusMap = {
  tenant_wide: {
    label: 'i18n.lib.status_maps.dashboard_visibility.tenant_wide',
    variant: 'info',
  },
  private: {
    label: 'i18n.lib.status_maps.dashboard_visibility.private',
    variant: 'secondary',
  },
};

export const DASHBOARD_START_PAGE: StatusMap = {
  start_page: {
    label: 'i18n.lib.status_maps.dashboard_start_page.start_page',
    variant: 'info',
  },
};

export const SEGMENT_ORIGIN: StatusMap = {
  owner: {
    label: 'i18n.lib.status_maps.segment_origin.owner',
    variant: 'secondary',
  },
  shared: {
    label: 'i18n.lib.status_maps.segment_origin.shared',
    variant: 'outline',
  },
};

export const REMINDER_STATE: StatusMap = {
  created: {
    label: 'i18n.lib.status_maps.reminder_state.created',
    variant: 'outline',
  },
  due: {
    label: 'i18n.lib.status_maps.reminder_state.due',
    variant: 'warning',
  },
  completed: {
    label: 'i18n.lib.status_maps.reminder_state.completed',
    variant: 'success',
  },
};

export const AGING_THRESHOLD_COLOR: StatusMap = {
  amber: {
    label: 'i18n.lib.status_maps.aging_threshold_color.amber',
    variant: 'warning',
  },
  red: {
    label: 'i18n.lib.status_maps.aging_threshold_color.red',
    variant: 'destructive',
  },
};

export const AGING_RULE_STATE: StatusMap = {
  active: {
    label: 'i18n.lib.status_maps.aging_rule_state.active',
    variant: 'success',
  },
  inactive: {
    label: 'i18n.lib.status_maps.aging_rule_state.inactive',
    variant: 'outline',
  },
};

export const MERGE_RULE_STATE: StatusMap = {
  active: {
    label: 'i18n.lib.status_maps.merge_rule_state.active',
    variant: 'success',
  },
  inactive: {
    label: 'i18n.lib.status_maps.merge_rule_state.inactive',
    variant: 'outline',
  },
};

export const NOTIFICATION_RULE_STATE: StatusMap = {
  active: {
    label: 'i18n.lib.status_maps.notification_rule_state.active',
    variant: 'success',
  },
  inactive: {
    label: 'i18n.lib.status_maps.notification_rule_state.inactive',
    variant: 'outline',
  },
};

export const MERGE_RULE_MODE: StatusMap = {
  allow: {
    label: 'i18n.lib.status_maps.merge_rule_mode.allow',
    variant: 'success',
  },
  deny: {
    label: 'i18n.lib.status_maps.merge_rule_mode.deny',
    variant: 'destructive',
  },
};

export const PROMOTION_RUN_STATUS: StatusMap = {
  draft: {
    label: 'i18n.lib.status_maps.promotion_run_status.draft',
    variant: 'outline',
  },
  awaiting_approval: {
    label: 'i18n.lib.status_maps.promotion_run_status.awaiting_approval',
    variant: 'info',
  },
  approved: {
    label: 'i18n.lib.status_maps.promotion_run_status.approved',
    variant: 'info',
  },
  rejected: {
    label: 'i18n.lib.status_maps.promotion_run_status.rejected',
    variant: 'destructive',
  },
  applying: {
    label: 'i18n.lib.status_maps.promotion_run_status.applying',
    variant: 'warning',
  },
  completed: {
    label: 'i18n.lib.status_maps.promotion_run_status.completed',
    variant: 'success',
  },
  failed: {
    label: 'i18n.lib.status_maps.promotion_run_status.failed',
    variant: 'destructive',
  },
  rolled_back: {
    label: 'i18n.lib.status_maps.promotion_run_status.rolled_back',
    variant: 'secondary',
  },
};

export const PROMOTION_WRITE_ACTION: StatusMap = {
  created: {
    label: 'i18n.lib.status_maps.promotion_write_action.created',
    variant: 'success',
  },
  updated: {
    label: 'i18n.lib.status_maps.promotion_write_action.updated',
    variant: 'info',
  },
  removed: {
    label: 'i18n.lib.status_maps.promotion_write_action.removed',
    variant: 'secondary',
  },
  skipped: {
    label: 'i18n.lib.status_maps.promotion_write_action.skipped',
    variant: 'destructive',
  },
};

export const PROMOTION_DIFF_STATE: StatusMap = {
  added: {
    label: 'i18n.lib.status_maps.promotion_diff_state.added',
    variant: 'success',
  },
  removed: {
    label: 'i18n.lib.status_maps.promotion_diff_state.removed',
    variant: 'destructive',
  },
  modified: {
    label: 'i18n.lib.status_maps.promotion_diff_state.modified',
    variant: 'info',
  },
  unchanged: {
    label: 'i18n.lib.status_maps.promotion_diff_state.unchanged',
    variant: 'secondary',
  },
  conflicted: {
    label: 'i18n.lib.status_maps.promotion_diff_state.conflicted',
    variant: 'warning',
  },
};

export const PROMOTION_CONFLICT_STATE: StatusMap = {
  undecided: {
    label: 'i18n.lib.status_maps.promotion_conflict_state.undecided',
    variant: 'destructive',
  },
  decided: {
    label: 'i18n.lib.status_maps.promotion_conflict_state.decided',
    variant: 'secondary',
  },
};

export const MAINTENANCE_LOCK_RELEASE: StatusMap = {
  automatic: {
    label: 'i18n.lib.status_maps.maintenance_lock_release.automatic',
    variant: 'secondary',
  },
  manual: {
    label: 'i18n.lib.status_maps.maintenance_lock_release.manual',
    variant: 'info',
  },
  emergency: {
    label: 'i18n.lib.status_maps.maintenance_lock_release.emergency',
    variant: 'warning',
  },
};

export function resolveStatus(map: StatusMap, value: string): StatusEntry {
  return map[value] ?? { label: value, variant: 'outline' };
}

export const FIELD_RESTRICTION_STATE: StatusMap = {
  open: {
    label: 'i18n.lib.status_maps.field_restriction_state.open',
    variant: 'outline',
  },
  restricted: {
    label: 'i18n.lib.status_maps.field_restriction_state.restricted',
    variant: 'warning',
  },
};

export const SSO_DOMAIN_VERIFICATION: StatusMap = {
  verified: {
    label: 'i18n.lib.status_maps.sso_domain_verification.verified',
    variant: 'success',
  },
  unverified: {
    label: 'i18n.lib.status_maps.sso_domain_verification.unverified',
    variant: 'outline',
  },
};

export const SSO_LOGIN_PROOF: StatusMap = {
  verified: {
    label: 'i18n.lib.status_maps.sso_login_proof.verified',
    variant: 'success',
  },
  outdated: {
    label: 'i18n.lib.status_maps.sso_login_proof.outdated',
    variant: 'warning',
  },
  missing: {
    label: 'i18n.lib.status_maps.sso_login_proof.missing',
    variant: 'outline',
  },
};

export const APPROVAL_DEADLINE: StatusMap = {
  overdue: {
    label: 'i18n.lib.status_maps.approval_deadline.overdue',
    variant: 'destructive',
  },
};

export const MERGE_ROLE: StatusMap = {
  target: {
    label: 'i18n.lib.status_maps.merge_role.target',
    variant: 'success',
  },
  source: {
    label: 'i18n.lib.status_maps.merge_role.source',
    variant: 'destructive',
  },
};
