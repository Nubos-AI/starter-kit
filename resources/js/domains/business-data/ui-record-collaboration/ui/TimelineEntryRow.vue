<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { edit as recordEdit } from '@/actions/App/Http/Controllers/Engine/RecordsController';
import type { TimelineEntry } from '@/domains/business-data/data';
import {
  readAttachmentPayload,
  readFieldChangePayload,
  readNotePayload,
  readRelationPayload,
  readReminderPayload,
} from '@/domains/business-data/data';
import { isAttachmentSource, isChangeSource, sourceIcon, sourceLabel } from '@/domains/business-data/data';
import { formatFieldValue } from '@/domains/business-data/util-record-format';
import { linksBesidesRecord, readSourceLinks } from '@/shared/business-data/utils/sourceLinks';
import { useI18n } from '@/shared/composables/useI18n';
import { formatDateTime, formatTimelineMoment } from '@/shared/lib/formatDate';
import { REMINDER_STATE } from '@/shared/lib/statusMaps';
import { moduleSystemFieldLabel, systemFieldLabel } from '@/shared/lib/systemValueLabels';
import type { FieldDefinition } from '@/shared/types/fields';
import { Badge } from '@/shared/ui/primitives/badge';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import SourceLinkList from '../../../../shared/links/ui/SourceLinkList.vue';
import TimelineFileEntry from './TimelineFileEntry.vue';

const { t } = useI18n();

const CHANNEL_LABELS: Record<string, string> = {
  web: t('i18n.components.engine.timeline.timeline_entry_row.web_app'),
  api: t('i18n.components.engine.timeline.timeline_entry_row.api'),
  automation: t('i18n.components.engine.timeline.timeline_entry_row.automation'),
  system: t('i18n.components.engine.timeline.timeline_entry_row.system'),
};

const SYSTEM_ACTOR_LABEL = t('i18n.components.engine.timeline.timeline_entry_row.system');

const EMPTY_VALUE_LABEL = '—';

const RELATION_ACTION_LABELS: Record<string, string> = {
  linked: t('i18n.components.engine.timeline.timeline_entry_row.linked'),
  unlinked: t('i18n.components.engine.timeline.timeline_entry_row.relationship_removed'),
};

const props = defineProps<{
  entry: TimelineEntry;
  recordId: string;
  fields: FieldDefinition[];
}>();

const emit = defineEmits<{ changed: [] }>();

const fieldChange = computed(() => readFieldChangePayload(props.entry.payload));

const note = computed(() => readNotePayload(props.entry.payload));

const reminder = computed(() => readReminderPayload(props.entry.payload));

const attachment = computed(() => readAttachmentPayload(props.entry.payload));

const relation = computed(() => readRelationPayload(props.entry.payload));

const activityLinks = computed(() => linksBesidesRecord(readSourceLinks(props.entry.payload?.links), props.recordId));

const relationActionLabel = computed<string>(
  () => RELATION_ACTION_LABELS[relation.value.action ?? ''] ?? sourceLabel(props.entry.sourceKey, t),
);

const relationCounterpartLabel = computed<string>(
  () => relation.value.counterpartNumber ?? relation.value.counterpartId ?? EMPTY_VALUE_LABEL,
);

const relationHref = computed<string | null>(() =>
  relation.value.counterpartId === null ? null : recordEdit.url({ record: relation.value.counterpartId }),
);

const icon = computed(() => sourceIcon(props.entry.sourceKey));

const changedField = computed<FieldDefinition | undefined>(() => {
  const key = fieldChange.value.fieldKey;

  return key === null ? undefined : props.fields.find((field) => field.key === key);
});

const fieldLabel = computed<string>(() => {
  const key = fieldChange.value.fieldKey;

  if (key === null) {
    return sourceLabel(props.entry.sourceKey, t);
  }

  const systemLabel = systemFieldLabel(key);

  return changedField.value?.label ?? (systemLabel === null ? (moduleSystemFieldLabel(key) ?? key) : t(systemLabel));
});

function displayValue(value: unknown): string {
  const formatted = formatFieldValue(value, changedField.value, {}, t);

  return formatted === '' ? EMPTY_VALUE_LABEL : formatted;
}

const moment = computed<string>(() => formatTimelineMoment(props.entry.occurredAt, t));

const channelLabel = computed<string | null>(() => {
  if (props.entry.channel === 'api' && props.entry.apiTokenLabel !== null) {
    return t('i18n.components.engine.timeline.timeline_entry_row.api_token', { value1: props.entry.apiTokenLabel });
  }

  return props.entry.channel === null ? null : (CHANNEL_LABELS[props.entry.channel] ?? null);
});

const actorLabel = computed<string>(() => props.entry.actorLabel ?? SYSTEM_ACTOR_LABEL);

const showsChannel = computed<boolean>(() => channelLabel.value !== null && props.entry.channel !== 'system');
</script>

<template>
  <li
    data-timeline-entry
    :data-timeline-entry-id="props.entry.id"
    :data-timeline-source="props.entry.sourceKey"
    class="relative flex gap-4 pb-6 last:pb-0"
  >
    <span class="relative z-10 flex size-8 shrink-0 items-center justify-center" aria-hidden="true">
      <span
        v-if="icon"
        data-timeline-marker="icon"
        class="flex size-8 items-center justify-center rounded-full border border-border bg-background text-muted-foreground"
      >
        <component :is="icon" class="size-4" />
      </span>
      <span v-else data-timeline-marker="dot" class="size-4 rounded-full border border-border bg-background" />
    </span>

    <div class="flex min-w-0 flex-1 flex-col gap-0.5 pt-1">
      <p
        v-if="isChangeSource(props.entry.sourceKey)"
        data-timeline-title
        class="flex flex-wrap items-baseline gap-x-1.5 text-sm"
      >
        <span class="font-medium"> {{ fieldLabel }}: </span>
        <span class="text-muted-foreground">
          {{ fieldChange.oldLabel ?? displayValue(fieldChange.oldValue) }}
        </span>
        <span aria-hidden="true" class="text-muted-foreground">→</span>
        <span>
          {{ fieldChange.newLabel ?? displayValue(fieldChange.newValue) }}
        </span>
      </p>

      <p
        v-else-if="props.entry.sourceKey === 'relation'"
        data-timeline-title
        class="flex flex-wrap items-baseline gap-x-1.5 text-sm"
      >
        <span class="font-medium">
          {{ relationActionLabel }}
        </span>
        <span v-if="relation.relationshipName" class="text-muted-foreground"> {{ relation.relationshipName }}: </span>
        <Link v-if="relationHref" data-timeline-relation-link :href="relationHref" class="underline underline-offset-2">
          {{ relationCounterpartLabel }}
        </Link>
        <span v-else>{{ relationCounterpartLabel }}</span>
      </p>

      <p v-else-if="props.entry.sourceKey === 'note'" data-timeline-title class="text-sm whitespace-pre-line">
        {{ note.body }}
      </p>

      <div v-else-if="props.entry.sourceKey === 'activity'" data-timeline-title class="flex flex-col gap-1 text-sm">
        <span class="font-medium">{{ props.entry.payload?.subject }}</span>
        <span class="text-xs text-muted-foreground"
          >{{ props.entry.payload?.typeName }} · {{ props.entry.payload?.assigneeName }}</span
        >
        <p v-if="props.entry.payload?.result" class="break-words whitespace-pre-wrap">
          {{ props.entry.payload.result }}
        </p>
        <SourceLinkList :links="activityLinks" />
      </div>

      <p
        v-else-if="props.entry.sourceKey === 'reminder'"
        data-timeline-title
        class="flex flex-wrap items-center gap-2 text-sm"
      >
        <span class="font-medium">{{ reminder.subject }}</span>
        <StatusBadge v-if="reminder.state" :map="REMINDER_STATE" :status="reminder.state" />
        <Badge v-if="reminder.overdue" variant="destructive">
          {{ t('i18n.components.engine.timeline.timeline_entry_row.overdue') }}
        </Badge>
        <span class="text-xs text-muted-foreground">
          {{ t('i18n.components.engine.timeline.timeline_entry_row.due') }}
          {{ formatDateTime(reminder.dueAt) }}
        </span>
      </p>

      <TimelineFileEntry
        v-else-if="props.entry.sourceKey === 'file'"
        :entry="props.entry"
        :record-id="props.recordId"
        @changed="emit('changed')"
      />

      <p
        v-else-if="isAttachmentSource(props.entry.sourceKey)"
        data-timeline-title
        class="flex flex-wrap items-center gap-2 text-sm"
      >
        <UiExtensionPoint name="timeline.attachment-link" :context="{ entry: props.entry, recordId: props.recordId }">
          <span class="font-medium">{{ attachment.fileName }}</span>
        </UiExtensionPoint>
        <span v-if="attachment.templateName" class="text-xs text-muted-foreground">
          {{ attachment.templateName }}
        </span>
      </p>

      <UiExtensionPoint v-else name="timeline.entry-title" :context="{ entry: props.entry }">
        <p data-timeline-title class="text-sm font-medium">
          {{ sourceLabel(props.entry.sourceKey, t) }}
        </p>
      </UiExtensionPoint>

      <div data-timeline-meta class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
        <time :datetime="props.entry.occurredAt">{{ moment }}</time>
        <span aria-hidden="true">·</span>
        <UiExtensionPoint name="timeline.entry-actor" :context="{ entry: props.entry }">
          <span>{{ actorLabel }}</span>
        </UiExtensionPoint>
        <span v-if="showsChannel">({{ channelLabel }})</span>
      </div>
    </div>
  </li>
</template>
