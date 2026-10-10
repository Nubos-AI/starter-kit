<script setup lang="ts">
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import type { TimelineActorType, TimelineFilterState } from '@/types/timeline';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    filterState: TimelineFilterState;
    actorOptions: SelectOption[];
}>();

const emit = defineEmits<{
    'update:filterState': [state: TimelineFilterState];
}>();

const ALL = '__all__';

const actorTypeLabels: Record<TimelineActorType, string> = {
    user: t('i18n.components.engine.timeline.timeline_filters.user'),
    automation: t(
        'i18n.components.engine.timeline.timeline_filters.automation',
    ),
    system: t('i18n.components.engine.timeline.timeline_filters.system'),
};

function patch(partial: Partial<TimelineFilterState>): void {
    emit('update:filterState', { ...props.filterState, ...partial });
}

function onActorType(value: string): void {
    patch({
        actorType:
            value === ALL || !(value in actorTypeLabels)
                ? null
                : (value as TimelineActorType),
    });
}

function onActor(value: string): void {
    patch({ actorId: value === ALL ? null : value });
}

function onDate(
    key: 'occurredFrom' | 'occurredTo',
    value: string | number,
): void {
    patch({ [key]: value === '' ? null : String(value) });
}
</script>

<template>
    <div class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <Label for="timeline-filter-from" class="text-xs">{{
                t('i18n.components.engine.timeline.timeline_filters.from')
            }}</Label>
            <Input
                id="timeline-filter-from"
                type="date"
                class="h-8 w-40"
                :model-value="props.filterState.occurredFrom ?? ''"
                @update:model-value="onDate('occurredFrom', $event)"
            />
        </div>

        <div class="flex flex-col gap-1">
            <Label for="timeline-filter-to" class="text-xs">{{
                t('i18n.components.engine.timeline.timeline_filters.to')
            }}</Label>
            <Input
                id="timeline-filter-to"
                type="date"
                class="h-8 w-40"
                :model-value="props.filterState.occurredTo ?? ''"
                @update:model-value="onDate('occurredTo', $event)"
            />
        </div>

        <div class="flex flex-col gap-1">
            <Label for="timeline-filter-actor-type" class="text-xs">
                {{
                    t(
                        'i18n.components.engine.timeline.timeline_filters.actor_type',
                    )
                }}
            </Label>
            <Select
                :model-value="props.filterState.actorType ?? ALL"
                @update:model-value="onActorType(String($event))"
            >
                <SelectTrigger id="timeline-filter-actor-type" class="h-8 w-40">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.timeline.timeline_filters.all',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">{{
                        t(
                            'i18n.components.engine.timeline.timeline_filters.all',
                        )
                    }}</SelectItem>
                    <SelectItem
                        v-for="(label, type) in actorTypeLabels"
                        :key="type"
                        :value="type"
                    >
                        {{ label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div v-if="props.actorOptions.length > 0" class="flex flex-col gap-1">
            <Label for="timeline-filter-actor" class="text-xs">{{
                t('i18n.components.engine.timeline.timeline_filters.actor')
            }}</Label>
            <Combobox
                id="timeline-filter-actor"
                class="h-8 w-48"
                :aria-label="
                    t('i18n.components.engine.timeline.timeline_filters.actor')
                "
                :placeholder="
                    t('i18n.components.engine.timeline.timeline_filters.all')
                "
                :options="[
                    {
                        value: ALL,
                        label: t(
                            'i18n.components.engine.timeline.timeline_filters.all',
                        ),
                    },
                    ...props.actorOptions,
                ]"
                :searchable="props.actorOptions.length > 8"
                :model-value="props.filterState.actorId ?? ALL"
                @update:model-value="onActor(String($event))"
            />
        </div>
    </div>
</template>
