import type { GoalListRow } from '@/types/goals';
import type {
    ReportAggregation,
    ReportExecutionMode,
    ReportGroupingBucket,
    ReportObjectTypeRef,
    ReportPresentation,
    ReportResult,
} from '@/types/reports';

export type DashboardActionRefusalReason = 'not_owner' | 'not_visible';

export type DashboardColumnSpan = 1 | 2 | 3;

export type ShareGranteeType = 'user' | 'team' | 'role';

export interface DashboardRow {
    id: string;
    name: string;
    description: string | null;
    owner_id: string;
    is_owner: boolean;
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
    user: 'Nutzer',
    team: 'Team',
    role: 'Rolle',
};

export const COLUMN_SPAN_LABELS: Record<DashboardColumnSpan, string> = {
    1: '1 Spalte',
    2: '2 Spalten',
    3: '3 Spalten',
};

export const COLUMN_SPAN_CLASS: Record<DashboardColumnSpan, string> = {
    1: 'md:col-span-1',
    2: 'md:col-span-2',
    3: 'md:col-span-3',
};

export const COLUMN_SPAN_VALUES: DashboardColumnSpan[] = [1, 2, 3];

export const WIDGET_UNTITLED_LABEL = 'Kachel ohne Titel';

export const DASHBOARD_WITHOUT_DESCRIPTION =
    'Für dieses Dashboard wurde noch keine Beschreibung hinterlegt.';

export const DASHBOARD_EMPTY_TITLE = 'Noch keine Kachel vorhanden';

export const DASHBOARD_EMPTY_DESCRIPTION =
    'Fügen Sie eine erste Kachel hinzu, um Ihre Zahlen auf diesem Dashboard zu sehen.';

export const DEFINER_SHARE_WARNING =
    'Mindestens eine Kachel dieses Dashboards rechnet mit den Rechten der Person, die sie eingerichtet hat. Wer das Dashboard erhält, sieht damit Zahlen, die er selbst nicht auswerten dürfte.';

export const TENANT_WIDE_LABEL = 'Mandantenweit sichtbar';

export const TENANT_WIDE_HINT =
    'Alle Personen dieses Mandanten sehen das Dashboard, ohne eine eigene Freigabe zu erhalten.';

export const SHARE_UNKNOWN_GRANTEE = 'Unbekannter Empfänger';

export const DASHBOARD_ADD_WIDGET_LABEL = 'Widget hinzufügen';

export const WIDGET_EDITOR_CREATE_TITLE = 'Kachel hinzufügen';

export const WIDGET_EDITOR_EDIT_TITLE = 'Kachel bearbeiten';

export const WIDGET_SEGMENT_PREFILL_ERROR =
    'Der Filter des Segments konnte nicht übernommen werden.';

const ACTION_REFUSAL_MESSAGES: Record<string, string> = {
    not_owner:
        'Nur wer dieses Dashboard angelegt hat, darf es ändern oder löschen.',
    not_visible: 'Dieses Dashboard steht Ihnen nicht mehr zur Verfügung.',
};

const ACTION_REFUSAL_FALLBACK =
    'Diese Aktion ist für dieses Dashboard derzeit nicht möglich.';

export function resolveDashboardActionRefusal(
    reason: string | null,
): string | undefined {
    if (reason === null) {
        return undefined;
    }

    return ACTION_REFUSAL_MESSAGES[reason] ?? ACTION_REFUSAL_FALLBACK;
}

export function clampColumnSpan(value: number): DashboardColumnSpan {
    if (!Number.isFinite(value)) {
        return 1;
    }

    return Math.min(3, Math.max(1, Math.round(value))) as DashboardColumnSpan;
}

export function widgetTitle(title: string | null): string {
    return title === null || title.trim() === ''
        ? WIDGET_UNTITLED_LABEL
        : title;
}
