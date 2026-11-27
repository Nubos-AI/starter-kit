import type { ActionRefusalCatalogue } from '@/shared/lib/actionRefusal';
import type { GoalListRow } from './goals';
import type {
  ReportAggregation,
  ReportExecutionMode,
  ReportGroupingBucket,
  ReportObjectTypeRef,
  ReportPresentation,
  ReportResult,
} from './reports';

export type DashboardActionRefusalReason = 'not_owner' | 'not_visible' | 'system';

export type DashboardColumnSpan = 1 | 2 | 3;

export type ShareGranteeType = 'user' | 'team' | 'role';

export interface DashboardRow {
  id: string;
  name: string;
  description: string | null;
  owner_id: string;
  is_owner: boolean;
  is_system: boolean;
  is_tenant_wide: boolean;
  is_default: boolean;
  can_update: boolean;
  can_delete: boolean;
  can_share: boolean;
  update_reason: string | null;
  delete_reason: string | null;
  share_reason: string | null;
  has_definer_widget: boolean;
  updated_at: string | null;
}

export interface DashboardWidgetDefinition {
  object_type_id: string;
  filter_definition: unknown;
  aggregation_type: ReportAggregation | null;
  aggregation_field_key: string | null;
  group_by_field_key: string | null;
  group_by_bucket: ReportGroupingBucket | null;
  series_field_key: string | null;
}

export interface DashboardWidgetMeta {
  id: string;
  dashboard_id: string;
  report_id: string | null;
  goal_id: string | null;
  source_key: string | null;
  title: string | null;
  chart_type: ReportPresentation | null;
  definition: DashboardWidgetDefinition | null;
  position: number;
  column_span: DashboardColumnSpan;
  updated_at: string | null;
}

export interface DashboardWidgetNotice {
  reason: string;
  message: string;
}

export interface DashboardWidgetTile {
  widget_id: string;
  title: string | null;
  chart_type: ReportPresentation | null;
  position: number;
  column_span: DashboardColumnSpan;
  report_id: string | null;
  goal_id: string | null;
  goal: GoalListRow | null;
  object_type: ReportObjectTypeRef | null;
  execution_mode: ReportExecutionMode;
  generated_at: string;
  result: ReportResult | null;
  currency: string | null;
  bucket: ReportGroupingBucket | null;
  notice: DashboardWidgetNotice | null;
}

export interface DashboardShare {
  id: string;
  grantee_type: ShareGranteeType | null;
  grantee_id: string;
  grantee_name: string | null;
  can_edit: boolean;
  granted_at: string | null;
}

export const SHARE_GRANTEE_LABELS: Record<ShareGranteeType, string> = {
  user: 'i18n.types.dashboards.share_grantee_labels.user',
  team: 'i18n.types.dashboards.share_grantee_labels.team',
  role: 'i18n.types.dashboards.share_grantee_labels.role',
};

export const COLUMN_SPAN_LABELS: Record<DashboardColumnSpan, string> = {
  1: 'i18n.types.dashboards.column_span_labels.span_1',
  2: 'i18n.types.dashboards.column_span_labels.span_2',
  3: 'i18n.types.dashboards.column_span_labels.span_3',
};

export const COLUMN_SPAN_CLASS: Record<DashboardColumnSpan, string> = {
  1: 'md:col-span-1',
  2: 'md:col-span-2',
  3: 'md:col-span-3',
};

export const COLUMN_SPAN_VALUES: DashboardColumnSpan[] = [1, 2, 3];

export const WIDGET_UNTITLED_LABEL = 'i18n.types.dashboards.widget_untitled_label';

export const DASHBOARD_WITHOUT_DESCRIPTION = 'i18n.types.dashboards.dashboard_without_description';

export const DASHBOARD_EMPTY_TITLE = 'i18n.types.dashboards.dashboard_empty_title';

export const DASHBOARD_EMPTY_DESCRIPTION = 'i18n.types.dashboards.dashboard_empty_description';

export const DEFINER_SHARE_WARNING = 'i18n.types.dashboards.definer_share_warning';

export const TENANT_WIDE_LABEL = 'i18n.types.dashboards.tenant_wide_label';

export const TENANT_WIDE_HINT = 'i18n.types.dashboards.tenant_wide_hint';

export const SHARE_UNKNOWN_GRANTEE = 'i18n.types.dashboards.share_unknown_grantee';

export const DASHBOARD_ADD_WIDGET_LABEL = 'i18n.types.dashboards.dashboard_add_widget_label';

export const WIDGET_EDITOR_CREATE_TITLE = 'i18n.types.dashboards.widget_editor_create_title';

export const WIDGET_EDITOR_EDIT_TITLE = 'i18n.types.dashboards.widget_editor_edit_title';

export const WIDGET_SEGMENT_PREFILL_ERROR = 'i18n.types.dashboards.widget_segment_prefill_error';

export const dashboardActionRefusals: ActionRefusalCatalogue = {
  messages: {
    not_owner: 'i18n.types.dashboards.action_refusal_messages.not_owner',
    not_visible: 'i18n.types.dashboards.action_refusal_messages.not_visible',
    system: 'i18n.types.dashboards.action_refusal_messages.system',
  },
  fallback: 'i18n.types.dashboards.action_refusal_fallback',
};

export function clampColumnSpan(value: number): DashboardColumnSpan {
  if (!Number.isFinite(value)) {
    return 1;
  }

  return Math.min(3, Math.max(1, Math.round(value))) as DashboardColumnSpan;
}

export function widgetTitle(title: string | null, t: (key: string) => string): string {
  return title === null || title.trim() === '' ? t(WIDGET_UNTITLED_LABEL) : title;
}
