<script setup lang="ts">
import { Plus, Share2, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import SegmentSaveDialog from '@/components/engine/segment/SegmentSaveDialog.vue';
import SegmentShareDialog from '@/components/engine/segment/SegmentShareDialog.vue';
import SegmentStates from '@/components/engine/segment/SegmentStates.vue';
import type { SegmentStateKind } from '@/components/engine/segment/SegmentStates.vue';
import { Badge } from '@/components/ui/badge';
import { IconActionButton } from '@/components/ui/icon-action-button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useSegments } from '@/composables/useSegments';
import type {
    SegmentSelection,
    SegmentSummary,
} from '@/composables/useSegments';
import {
    emptyGranteeOptions,
    fetchGranteeOptions,
} from '@/composables/useSegmentShares';
import type { GranteeOptionMap } from '@/composables/useSegmentShares';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

const { t } = useI18n();

const CREATE_SEGMENT_VALUE = 'create-segment';

const props = withDefaults(
    defineProps<{
        objectType: RecordObjectType;
        fields: FieldDefinition[];
        activeSegmentId?: string | null;
        allowShare?: boolean;
    }>(),
    { activeSegmentId: null, allowShare: true },
);

const emit = defineEmits<{
    apply: [selection: SegmentSelection];
    saved: [segment: SegmentSummary];
    clear: [];
}>();

const { segments, groups, loading, error, load } = useSegments(
    props.objectType,
);

interface SegmentBucket {
    key: 'default' | 'system' | 'own' | 'shared';
    label: string;
    items: SegmentSummary[];
}

const buckets = computed<SegmentBucket[]>(() =>
    [
        {
            key: 'default',
            label: t('i18n.components.engine.segment.segment_bar.default'),
            items: groups.value.default,
        },
        {
            key: 'system',
            label: t('i18n.components.engine.segment.segment_bar.system'),
            items: groups.value.system,
        },
        {
            key: 'own',
            label: t('i18n.components.engine.segment.segment_bar.own'),
            items: groups.value.own,
        },
        {
            key: 'shared',
            label: t('i18n.components.engine.segment.segment_bar.shared'),
            items: groups.value.shared,
        },
    ].filter((bucket): bucket is SegmentBucket => bucket.items.length > 0),
);

const segmentState = computed<SegmentStateKind>(() => {
    if (error.value !== null) {
        return 'error';
    }

    if (loading.value) {
        return 'loading';
    }

    if (segments.value.length === 0) {
        return 'empty';
    }

    return 'ready';
});

const dialogOpen = ref<boolean>(false);
const shareOpen = ref<boolean>(false);
const shareOptions = ref<GranteeOptionMap>(emptyGranteeOptions());

const activeSegment = computed<SegmentSummary | null>(
    () =>
        segments.value.find(
            (segment) => segment.id === (props.activeSegmentId ?? ''),
        ) ?? null,
);

const shareableSegment = computed<SegmentSummary | null>(() =>
    activeSegment.value !== null &&
    activeSegment.value.is_owner &&
    !activeSegment.value.is_system
        ? activeSegment.value
        : null,
);

async function onShare(): Promise<void> {
    const segment = shareableSegment.value;

    if (segment === null) {
        return;
    }

    try {
        shareOptions.value = await fetchGranteeOptions(segment.id);
    } catch {
        shareOptions.value = emptyGranteeOptions();
    }

    shareOpen.value = true;
}

function onSelect(value: unknown): void {
    if (value === CREATE_SEGMENT_VALUE) {
        dialogOpen.value = true;
    }
}

function onApply(segment: SegmentSummary): void {
    if (segment.id.trim() === '') {
        return;
    }

    emit('apply', { segment });
}

async function onSaved(segment: SegmentSummary): Promise<void> {
    emit('saved', segment);
    await load();
}

onMounted(load);
</script>

<template>
    <section
        :aria-label="t('i18n.components.engine.segment.segment_bar.segments')"
        class="flex items-center gap-2"
    >
        <SegmentStates
            v-if="segmentState !== 'ready'"
            :state="segmentState"
            :error-message="error ?? undefined"
            class="min-w-56 flex-1"
            @retry="load"
            @create="dialogOpen = true"
        />
        <Select
            v-else
            :model-value="activeSegmentId ?? ''"
            @update:model-value="onSelect"
        >
            <SelectTrigger data-segment-picker class="min-w-56 flex-1">
                <SelectValue
                    :placeholder="
                        t(
                            'i18n.components.engine.segment.segment_bar.select_segment',
                        )
                    "
                />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    :value="CREATE_SEGMENT_VALUE"
                    data-segment-create
                    @click="dialogOpen = true"
                >
                    <Plus class="size-4" />
                    <span>{{
                        t(
                            'i18n.components.engine.segment.segment_bar.create_new_segment',
                        )
                    }}</span>
                </SelectItem>

                <SelectSeparator />

                <SelectGroup
                    v-for="bucket in buckets"
                    :key="bucket.key"
                    :data-segment-group="bucket.key"
                >
                    <SelectLabel>{{ bucket.label }}</SelectLabel>
                    <SelectItem
                        v-for="segment in bucket.items"
                        :key="segment.id"
                        :value="segment.id"
                        data-segment-entry
                        :data-segment-id="segment.id"
                        @click="onApply(segment)"
                    >
                        <span>{{ segment.name }}</span>
                        <Badge v-if="segment.is_default" variant="secondary">
                            {{
                                t(
                                    'i18n.components.engine.segment.segment_bar.default',
                                )
                            }}
                        </Badge>
                        <Badge v-else-if="segment.is_system" variant="outline">
                            {{
                                t(
                                    'i18n.components.engine.segment.segment_bar.system',
                                )
                            }}
                        </Badge>
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>

        <IconActionButton
            v-if="activeSegmentId"
            :icon="X"
            :label="
                t('i18n.components.engine.segment.segment_bar.close_segment')
            "
            test-id="segment-clear"
            class="shrink-0"
            @click="emit('clear')"
        />

        <IconActionButton
            v-if="props.allowShare && shareableSegment"
            :icon="Share2"
            :label="
                t('i18n.components.engine.segment.segment_bar.share_segment')
            "
            test-id="segment-share"
            class="shrink-0"
            @click="onShare"
        />

        <SegmentSaveDialog
            :open="dialogOpen"
            :object-type="objectType"
            :fields="fields"
            @update:open="dialogOpen = $event"
            @saved="onSaved"
        />

        <SegmentShareDialog
            v-if="props.allowShare && shareableSegment"
            :open="shareOpen"
            :segment="shareableSegment"
            :grantee-options="shareOptions"
            @update:open="shareOpen = $event"
        />
    </section>
</template>
