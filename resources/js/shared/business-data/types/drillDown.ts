export interface ReportDrillDownSelection {
  group: string;
  series: string | null;
}

export interface ReportDrillDownReportParams {
  report: string;
  dashboard?: null;
  widget?: null;
  group: string;
  series: string | null;
}

export interface ReportDrillDownWidgetParams {
  report?: null;
  dashboard: string;
  widget: string;
  group: string;
  series: string | null;
}

export type ReportDrillDownParams = ReportDrillDownReportParams | ReportDrillDownWidgetParams;

const COLLECTOR_TOKEN = 'other';

const EMPTY_TOKEN = 'null';

const VALUE_TOKEN_PREFIX = 'v:';

export function drillDownToken(value: string | null, isOther: boolean): string {
  if (isOther) {
    return COLLECTOR_TOKEN;
  }

  return value === null ? EMPTY_TOKEN : `${VALUE_TOKEN_PREFIX}${value}`;
}

export function isCollectorToken(token: string): boolean {
  return token === COLLECTOR_TOKEN;
}
