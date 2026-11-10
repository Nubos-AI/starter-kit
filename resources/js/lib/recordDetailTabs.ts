export const RECORD_DETAIL_TABS = [
    { value: 'notes', label: 'Notizen' },
    { value: 'activity', label: 'Aktivität' },
    { value: 'reminders', label: 'Erinnerungen' },
    { value: 'files', label: 'Dateien' },
] as const;

export type RecordDetailTab = (typeof RECORD_DETAIL_TABS)[number]['value'];
