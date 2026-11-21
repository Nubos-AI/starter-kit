export const RECORD_DETAIL_TABS = [
  { value: 'notes', label: 'i18n.lib.record_detail_tabs.notes' },
  { value: 'activity', label: 'i18n.lib.record_detail_tabs.activity' },
  { value: 'reminders', label: 'i18n.lib.record_detail_tabs.reminders' },
  { value: 'files', label: 'i18n.lib.record_detail_tabs.files' },
] as const;

export type RecordDetailTab = (typeof RECORD_DETAIL_TABS)[number]['value'];
