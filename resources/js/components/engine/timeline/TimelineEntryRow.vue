<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { edit as recordEdit } from '@/actions/App/Http/Controllers/Engine/RecordsController';
import TimelineFileEntry from '@/components/engine/files/TimelineFileEntry.vue';
import {
    isAttachmentSource,
    sourceIcon,
    sourceLabel,
    stageFacetLabel,
} from '@/components/engine/timeline/sourceLabels';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime, formatTimelineMoment } from '@/lib/formatDate';
import { formatFieldValue } from '@/lib/formatFieldValue';
import { REMINDER_STATE, resolveStatus } from '@/lib/statusMaps';
import { systemFieldLabel } from '@/lib/systemValueLabels';
import type { FieldDefinition } from '@/types/fields';
import {
    readAttachmentPayload,
    readFieldChangePayload,
    readNotePayload,
    readRelationPayload,
    readReminderPayload,
    readStageChangePayload,
} from '@/types/timeline';
import type { TimelineEntry } from '@/types/timeline';

const { t } = useI18n();

const STAGE_FIELD_LABEL = t(
    'i18n.components.engine.timeline.timeline_entry_row.stage',
);

const CHANNEL_LABELS: Record<string, string> = {
    web: t('i18n.components.engine.timeline.timeline_entry_row.web_app'),
    api: t('i18n.components.engine.timeline.timeline_entry_row.api'),
    automation: t(
        'i18n.components.engine.timeline.timeline_entry_row.automation',
    ),
    system: t('i18n.components.engine.timeline.timeline_entry_row.system'),
};

const SYSTEM_ACTOR_LABEL = t(
    'i18n.components.engine.timeline.timeline_entry_row.system',
);

const EMPTY_VALUE_LABEL = '—';

const RELATION_ACTION_LABELS: Record<string, string> = {
    linked: t('i18n.components.engine.timeline.timeline_entry_row.linked'),
    unlinked: t(
        'i18n.components.engine.timeline.timeline_entry_row.relationship_removed',
    ),
};

const props = defineProps<{
    entry: TimelineEntry;
    recordId: string;
    fields: FieldDefinition[];
}>();

const emit = defineEmits<{ changed: [] }>();

const fieldChange = computed(() => readFieldChangePayload(props.entry.payload));

const stageChange = computed(() => readStageChangePayload(props.entry.payload));

const note = computed(() => readNotePayload(props.entry.payload));

const reminder = computed(() => readReminderPayload(props.entry.payload));

const attachment = computed(() => readAttachmentPayload(props.entry.payload));

const relation = computed(() => readRelationPayload(props.entry.payload));

const relationActionLabel = computed<string>(
    () =>
        RELATION_ACTION_LABELS[relation.value.action ?? ''] ??
        sourceLabel(props.entry.sourceKey),
);

const relationCounterpartLabel = computed<string>(
    () =>
        relation.value.counterpartNumber ??
        relation.value.counterpartId ??
        EMPTY_VALUE_LABEL,
);

const relationHref = computed<string | null>(() =>
    relation.value.counterpartId === null
        ? null
        : recordEdit.url({ record: relation.value.counterpartId }),
);

const icon = computed(() => sourceIcon(props.entry.sourceKey));

const changedField = computed<FieldDefinition | undefined>(() => {
    const key = fieldChange.value.fieldKey;

    return key === null
        ? undefined
        : props.fields.find((field) => field.key === key);
});

const fieldLabel = computed<string>(() => {
    const key = fieldChange.value.fieldKey;

    if (key === null) {
        return sourceLabel(props.entry.sourceKey);
    }

    return changedField.value?.label ?? systemFieldLabel(key) ?? key;
});

function displayValue(value: unknown): string {
    const formatted = formatFieldValue(value, changedField.value);

    return formatted === '' ? EMPTY_VALUE_LABEL : formatted;
}

const reminderState = computed(() =>
    resolveStatus(REMINDER_STATE, reminder.value.state ?? ''),
);

const moment = computed<string>(() =>
    formatTimelineMoment(props.entry.occurredAt),
);

const channelLabel = computed<string | null>(() =>
    props.entry.channel === null
        ? null
        : (CHANNEL_LABELS[props.entry.channel] ?? null),
);

const actorLabel = computed<string>(
    () => props.entry.actorLabel ?? SYSTEM_ACTOR_LABEL,
);

const showsChannel = computed<boolean>(
    () => channelLabel.value !== null && props.entry.channel !== 'system',
);
</script>

<template>
    <li
        data-timeline-entry
        :data-timeline-entry-id="props.entry.id"
        :data-timeline-source="props.entry.sourceKey"
        class="relative flex gap-4 pb-6 last:pb-0"
    >
        <span
            class="relative z-10 flex size-8 shrink-0 items-center justify-center"
            aria-hidden="true"
        >
            <span
                v-if="icon"
                data-timeline-marker="icon"
                class="flex size-8 items-center justify-center rounded-full border border-border bg-background text-muted-foreground"
            >
                <component :is="icon" class="size-4" />
            </span>
            <span
                v-else
                data-timeline-marker="dot"
                class="size-4 rounded-full border border-border bg-background"
            />
        </span>

        <div class="flex min-w-0 flex-1 flex-col gap-0.5 pt-1">
            <p
                v-if="
                    props.entry.sourceKey === 'field_change' ||
                    props.entry.sourceKey === 'stage_change'
                "
                data-timeline-title
                class="flex flex-wrap items-baseline gap-x-1.5 text-sm"
            >
                <span class="font-medium">
                    {{
                        props.entry.sourceKey === 'stage_change'
                            ? STAGE_FIELD_LABEL
                            : fieldLabel
                    }}:
                </span>
                <span class="text-muted-foreground">
                    {{
                        props.entry.sourceKey === 'stage_change'
                            ? stageFacetLabel(stageChange.oldStage)
                            : (fieldChange.oldLabel ??
                              displayValue(fieldChange.oldValue))
                    }}
                </span>
                <span aria-hidden="true" class="text-muted-foreground">→</span>
                <span>
                    {{
                        props.entry.sourceKey === 'stage_change'
                            ? stageFacetLabel(stageChange.newStage)
                            : (fieldChange.newLabel ??
                              displayValue(fieldChange.newValue))
                    }}
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
                <span
                    v-if="relation.relationshipName"
                    class="text-muted-foreground"
                >
                    {{ relation.relationshipName }}:
                </span>
                <Link
                    v-if="relationHref"
                    data-timeline-relation-link
                    :href="relationHref"
                    class="underline underline-offset-2"
                >
                    {{ relationCounterpartLabel }}
                </Link>
                <span v-else>{{ relationCounterpartLabel }}</span>
            </p>

            <p
                v-else-if="props.entry.sourceKey === 'note'"
                data-timeline-title
                class="text-sm whitespace-pre-line"
            >
                {{ note.body }}
            </p>

            <div
                v-else-if="props.entry.sourceKey === 'activity'"
                data-timeline-title
                class="flex flex-col gap-1 text-sm"
            >
                <span class="font-medium">{{
                    props.entry.payload?.subject
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >{{ props.entry.payload?.typeName }} ·
                    {{ props.entry.payload?.assigneeName }}</span
                >
                <p
                    v-if="props.entry.payload?.result"
                    class="break-words whitespace-pre-wrap"
                >
                    {{ props.entry.payload.result }}
                </p>
            </div>

            <p
                v-else-if="props.entry.sourceKey === 'reminder'"
                data-timeline-title
                class="flex flex-wrap items-center gap-2 text-sm"
            >
                <span class="font-medium">{{ reminder.subject }}</span>
                <Badge v-if="reminder.state" :variant="reminderState.variant">
                    {{ reminderState.label }}
                </Badge>
                <Badge v-if="reminder.overdue" variant="destructive">
                    {{
                        t(
                            'i18n.components.engine.timeline.timeline_entry_row.overdue',
                        )
                    }}
                </Badge>
                <span class="text-xs text-muted-foreground">
                    {{
                        t(
                            'i18n.components.engine.timeline.timeline_entry_row.due',
                        )
                    }}
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
                <UiExtensionPoint
                    name="timeline.attachment-link"
                    :context="{ entry: props.entry, recordId: props.recordId }"
                >
                    <span class="font-medium">{{ attachment.fileName }}</span>
                </UiExtensionPoint>
                <span
                    v-if="attachment.templateName"
                    class="text-xs text-muted-foreground"
                >
                    {{ attachment.templateName }}
                </span>
            </p>

            <UiExtensionPoint
                v-else
                name="timeline.entry-title"
                :context="{ entry: props.entry }"
            >
                <p data-timeline-title class="text-sm font-medium">
                    {{ sourceLabel(props.entry.sourceKey) }}
                </p>
            </UiExtensionPoint>

            <div
                data-timeline-meta
                class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
            >
                <time :datetime="props.entry.occurredAt">{{ moment }}</time>
                <span aria-hidden="true">·</span>
                <UiExtensionPoint
                    name="timeline.entry-actor"
                    :context="{ entry: props.entry }"
                >
                    <span>{{ actorLabel }}</span>
                </UiExtensionPoint>
                <span v-if="showsChannel">({{ channelLabel }})</span>
            </div>
        </div>
    </li>
</template>
