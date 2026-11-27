import { Activity, FileText, Link2, MessageSquare, Merge, Paperclip, Timer } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { sharedModuleOptions } from '@/shared/lib/modules';
import { iconFor } from '@/shared/lib/navIcons';

export interface TimelineTab {
  value: string;
  label: string;
  sources: string[];
}

type Translate = (key: string) => string;

const UNKNOWN_SOURCE_LABEL = 'i18n.components.engine.timeline.source_labels.unknown_source';

const SOURCE_LABELS: Record<string, string> = {
  activity: 'i18n.components.engine.timeline.source_labels.source_activity',
  note: 'i18n.components.engine.timeline.source_labels.source_note',
  reminder: 'i18n.components.engine.timeline.source_labels.source_reminder',
  file: 'i18n.components.engine.timeline.source_labels.source_file',
  field_change: 'i18n.components.engine.timeline.source_labels.source_field_change',
  relation: 'i18n.components.engine.timeline.source_labels.source_relation',
  merge: 'i18n.components.engine.timeline.source_labels.source_merge',
};

export const TIMELINE_ACTIVITY_TAB = 'activity';

export const TIMELINE_TABS: TimelineTab[] = [
  {
    value: 'all',
    label: 'i18n.components.engine.timeline.source_labels.tab_all',
    sources: [],
  },
  {
    value: 'note',
    label: 'i18n.components.engine.timeline.source_labels.tab_notes',
    sources: ['note'],
  },
  {
    value: TIMELINE_ACTIVITY_TAB,
    label: 'i18n.components.engine.timeline.source_labels.tab_activity',
    sources: ['activity'],
  },
  {
    value: 'reminder',
    label: 'i18n.components.engine.timeline.source_labels.tab_reminders',
    sources: ['reminder'],
  },
  {
    value: 'file',
    label: 'i18n.components.engine.timeline.source_labels.tab_files',
    sources: ['file'],
  },
  {
    value: 'change',
    label: 'i18n.components.engine.timeline.source_labels.tab_changes',
    sources: ['field_change', 'relation', 'merge'],
  },
];

const SOURCE_ICONS: Record<string, LucideIcon> = {
  activity: Activity,
  note: MessageSquare,
  reminder: Timer,
  file: Paperclip,
  merge: Merge,
  relation: Link2,
};

export function sourceIcon(sourceKey: string): LucideIcon | null {
  return (
    SOURCE_ICONS[sourceKey] ??
    iconFor(sharedModuleOptions('timeline.sources').find((option) => option.value === sourceKey)?.icon) ??
    (isAttachmentSource(sourceKey) ? FileText : null)
  );
}

export function sourceLabel(sourceKey: string, t: Translate): string {
  const coreLabel = SOURCE_LABELS[sourceKey];

  if (coreLabel !== undefined) {
    return t(coreLabel);
  }

  return (
    [
      ...sharedModuleOptions('timeline.sources'),
      ...sharedModuleOptions('timeline.attachment-sources'),
      ...sharedModuleOptions('timeline.change-sources'),
    ].find((option) => option.value === sourceKey)?.label ?? t(UNKNOWN_SOURCE_LABEL)
  );
}

export function isChangeSource(sourceKey: string): boolean {
  return (
    sourceKey === 'field_change' ||
    sharedModuleOptions('timeline.change-sources').some((option) => option.value === sourceKey)
  );
}

export function isAttachmentSource(sourceKey: string): boolean {
  return (
    sourceKey === 'file' ||
    sharedModuleOptions('timeline.attachment-sources').some((option) => option.value === sourceKey)
  );
}
