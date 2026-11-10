<script setup lang="ts">
import { computed } from 'vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import TableLink from '@/components/data-grid/TableLink.vue';
import { recordRouteKey } from '@/lib/recordRouteKey';
import type { RecordPayload } from '@/types/records';

const BRANCH_WIDTH_REM = 1;

const props = defineProps<{
    record: RecordPayload | null;
}>();

const label = computed<string>(
    () => props.record?.title?.trim() || props.record?.recordNumber || '—',
);
const secondaryLabel = computed(() =>
    props.record?.title?.trim() &&
    props.record?.title !== props.record?.recordNumber
        ? props.record?.recordNumber
        : null,
);

const depth = computed<number>(() => props.record?.hierarchyDepth ?? 0);

const indentStyle = computed<Record<string, string> | undefined>(() =>
    depth.value < 2
        ? undefined
        : { paddingLeft: `${(depth.value - 1) * BRANCH_WIDTH_REM}rem` },
);

const href = computed<string | null>(() =>
    props.record === null
        ? null
        : RecordsController.show.url({ record: recordRouteKey(props.record) }),
);
</script>

<template>
    <span
        data-business-key-cell
        class="flex h-full min-w-0 items-center"
        :style="indentStyle"
    >
        <span
            v-if="depth > 0"
            data-hierarchy-branch
            aria-hidden="true"
            class="mr-1 mb-[0.35rem] h-2.5 w-3 shrink-0 border-b border-l border-border"
        />

        <TableLink
            v-if="href !== null"
            :href="href"
            data-testid="business-key-link"
            class="flex min-w-0 flex-col justify-center leading-tight"
            :title="label"
        >
            <span class="truncate font-semibold">{{ label }}</span>
            <span
                v-if="secondaryLabel"
                class="truncate text-xs font-normal text-muted-foreground"
                >{{ secondaryLabel }}</span
            >
        </TableLink>
        <span v-else>{{ label }}</span>
    </span>
</template>
