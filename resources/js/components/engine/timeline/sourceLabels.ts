import {
    Activity,
    Bot,
    FileText,
    Link2,
    MessageSquare,
    Merge,
    Paperclip,
    Timer,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { sharedModuleOptions } from '@/lib/modules';
import type { TimelineStageFacet } from '@/types/timeline';

export interface TimelineTab {
    value: string;
    label: string;
    sources: string[];
}

const UNKNOWN_SOURCE_LABEL = 'Ereignis';

const UNKNOWN_STAGE_LABEL = 'Unbekannte Stage';

const MISSING_STAGE_LABEL = 'Keine Stage';

const SOURCE_LABELS: Record<string, string> = {
    activity: 'Aktivität',
    note: 'Notiz',
    reminder: 'Erinnerung',
    file: 'Datei',
    automation_run: 'Automationslauf',
    field_change: 'Änderung',
    stage_change: 'Stagewechsel',
    relation: 'Verknüpfung',
    merge: 'Zusammenführung',
};

export const TIMELINE_ACTIVITY_TAB = 'activity';

export const TIMELINE_TABS: TimelineTab[] = [
    { value: 'all', label: 'Alle', sources: [] },
    { value: 'note', label: 'Notizen', sources: ['note'] },
    { value: TIMELINE_ACTIVITY_TAB, label: 'Aktivität', sources: ['activity'] },
    { value: 'reminder', label: 'Erinnerungen', sources: ['reminder'] },
    { value: 'file', label: 'Dateien', sources: ['file'] },
    {
        value: 'change',
        label: 'Änderungen',
        sources: ['field_change', 'stage_change', 'relation', 'merge'],
    },
];

const SOURCE_ICONS: Record<string, LucideIcon> = {
    activity: Activity,
    note: MessageSquare,
    reminder: Timer,
    file: Paperclip,
    automation_run: Bot,
    merge: Merge,
    relation: Link2,
};

export function sourceIcon(sourceKey: string): LucideIcon | null {
    return (
        SOURCE_ICONS[sourceKey] ??
        (isAttachmentSource(sourceKey) ? FileText : null)
    );
}

export function sourceLabel(sourceKey: string): string {
    return (
        SOURCE_LABELS[sourceKey] ??
        sharedModuleOptions('timeline.attachment-sources').find(
            (option) => option.value === sourceKey,
        )?.label ??
        UNKNOWN_SOURCE_LABEL
    );
}

export function stageFacetLabel(
    facet: TimelineStageFacet | null | undefined,
): string {
    if (facet === null || facet === undefined) {
        return MISSING_STAGE_LABEL;
    }

    const labels = facet.labels ?? {};

    return (
        labels.de ??
        Object.values(labels)[0] ??
        facet.key ??
        UNKNOWN_STAGE_LABEL
    );
}

export function isAttachmentSource(sourceKey: string): boolean {
    return (
        sourceKey === 'file' ||
        sharedModuleOptions('timeline.attachment-sources').some(
            (option) => option.value === sourceKey,
        )
    );
}
