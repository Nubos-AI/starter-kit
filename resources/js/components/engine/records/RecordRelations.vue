<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, onMounted, onUnmounted } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Combobox } from '@/components/ui/combobox';
import { MultiSelect } from '@/components/ui/multi-select';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
import { useI18n } from '@/composables/useI18n';
import { useRecordRelations } from '@/composables/useRecordRelations';
import { RELATIONS_PANEL_ID } from '@/lib/recordPanels';
import type { RelationEntry, RelationGroup } from '@/types/relations';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const HIERARCHY_ROLES = {
    incoming: t('i18n.components.engine.records.record_relations.parent'),
    outgoing: 'untergeordnet',
} as const;

const HIERARCHY_ROLES_PLURAL = {
    incoming: t('i18n.components.engine.records.record_relations.parents'),
    outgoing: 'untergeordnete',
} as const;

const NONE_VALUE = 'none';

const NONE_LABEL = t(
    'i18n.components.engine.records.record_relations.no_relationship',
);

const countFormatter = new Intl.NumberFormat('de-DE');

const props = withDefaults(
    defineProps<{
        recordId: string;
        objectTypeId?: string | null;
        readonly?: boolean;
    }>(),
    { objectTypeId: null, readonly: false },
);

const {
    groups,
    loading,
    error,
    load,
    link,
    unlink,
    loadMoreOptions,
    searchOptions,
} = useRecordRelations();

const { isOpen, setOpen } = useCollapsedSections(props.objectTypeId);

const open = computed<boolean>({
    get: () => isOpen(RELATIONS_PANEL_ID),
    set: (value) => setOpen(RELATIONS_PANEL_ID, value),
});

const SEARCH_DEBOUNCE_MS = 150;

const searchTimers = new Map<string, ReturnType<typeof setTimeout>>();

function serverSearch(group: RelationGroup): boolean {
    return group.entriesTruncated || group.candidatesTruncated;
}

function onOptionsSearch(group: RelationGroup, term: string): void {
    if (!serverSearch(group)) {
        return;
    }

    const key = groupKey(group);
    const pending = searchTimers.get(key);

    if (pending !== undefined) {
        clearTimeout(pending);
    }

    searchTimers.set(
        key,
        setTimeout(() => {
            searchTimers.delete(key);
            void searchOptions(props.recordId, group, term);
        }, SEARCH_DEBOUNCE_MS),
    );
}

function onOptionsLoadMore(group: RelationGroup): void {
    if (!serverSearch(group)) {
        return;
    }

    void loadMoreOptions(props.recordId, group);
}

onUnmounted(() => {
    searchTimers.forEach((timer) => clearTimeout(timer));
    searchTimers.clear();
});

function groupKey(group: RelationGroup): string {
    return `${group.relationshipTypeId}:${group.direction}`;
}

function roleLabel(group: RelationGroup): string {
    return group.isHierarchy
        ? HIERARCHY_ROLES[group.direction]
        : group.roleLabel;
}

function isLocked(group: RelationGroup): boolean {
    return props.readonly || !group.canEdit || loading.value;
}

function optionsFor(group: RelationGroup): SelectOption[] {
    const linked = group.entries.map(
        (entry): SelectOption => ({
            value: entry.recordId,
            label: entry.label,
        }),
    );

    const linkedIds = new Set(linked.map((option) => option.value));

    return [
        ...linked,
        ...group.candidates
            .filter((candidate) => !linkedIds.has(candidate.id))
            .map(
                (candidate): SelectOption => ({
                    value: candidate.id,
                    label: candidate.label,
                }),
            ),
    ];
}

function singleOptions(group: RelationGroup): SelectOption[] {
    return [{ value: NONE_VALUE, label: NONE_LABEL }, ...optionsFor(group)];
}

function singleValue(group: RelationGroup): string {
    return group.entries[0]?.recordId ?? NONE_VALUE;
}

function summaryLabel(group: RelationGroup): string {
    const count = group.entriesTotal;

    if (count === 0) {
        return NONE_LABEL;
    }

    if (count === 1 && group.entries.length === 1) {
        return group.entries[0].label;
    }

    const noun = group.isHierarchy
        ? HIERARCHY_ROLES_PLURAL[group.direction]
        : group.objectTypeName;

    return `${countFormatter.format(count)} ${noun}`;
}

function selectedIds(group: RelationGroup): string[] {
    return group.entries.map((entry) => entry.recordId);
}

function entryOf(group: RelationGroup, recordId: string): RelationEntry | null {
    return group.entries.find((entry) => entry.recordId === recordId) ?? null;
}

async function onSingleChange(
    group: RelationGroup,
    value: string | null,
): Promise<void> {
    const current = group.entries[0] ?? null;
    const picked = value === null || value === NONE_VALUE ? null : value;

    if (picked === (current?.recordId ?? null)) {
        return;
    }

    if (picked === null) {
        if (
            current === null ||
            !(await unlink(props.recordId, current.linkId))
        ) {
            return;
        }

        await load(props.recordId);

        return;
    }

    if (current !== null && !group.isHierarchy) {
        if (!(await unlink(props.recordId, current.linkId))) {
            return;
        }
    }

    const linked = await link(
        props.recordId,
        group.relationshipTypeId,
        group.direction,
        picked,
    );

    if (!linked) {
        return;
    }

    await load(props.recordId);
}

async function onMultiChange(
    group: RelationGroup,
    values: string[],
): Promise<void> {
    const current = selectedIds(group);
    const added = values.filter((value) => !current.includes(value));
    const removed = current.filter((value) => !values.includes(value));

    if (added.length === 0 && removed.length === 0) {
        return;
    }

    for (const recordId of removed) {
        const entry = entryOf(group, recordId);

        if (entry !== null) {
            await unlink(props.recordId, entry.linkId);
        }
    }

    for (const recordId of added) {
        await link(
            props.recordId,
            group.relationshipTypeId,
            group.direction,
            recordId,
        );
    }

    await load(props.recordId);
}

onMounted(() => {
    void load(props.recordId);
});
</script>

<template>
    <Collapsible v-model:open="open" as-child>
        <Card data-record-relations>
            <CardHeader>
                <CardTitle>
                    <CollapsibleTrigger as-child>
                        <Button
                            variant="ghost"
                            data-record-relations-toggle
                            class="h-auto w-full justify-between px-0 py-0 text-[length:inherit] leading-none font-semibold hover:bg-transparent has-[>svg]:px-0"
                        >
                            {{
                                t(
                                    'i18n.components.engine.records.record_relations.relationships',
                                )
                            }}
                            <ChevronDown
                                class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 ease-out"
                                :class="open ? '' : '-rotate-90'"
                                aria-hidden="true"
                            />
                        </Button>
                    </CollapsibleTrigger>
                </CardTitle>
            </CardHeader>

            <CollapsibleContent
                class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down"
            >
                <CardContent class="flex flex-col gap-4">
                    <div
                        v-for="group in groups"
                        :key="groupKey(group)"
                        data-relation-group
                        class="flex flex-col gap-1.5"
                    >
                        <div class="flex items-baseline gap-2">
                            <span
                                data-relation-name
                                class="text-sm font-medium"
                            >
                                {{ group.objectTypeName }}
                            </span>
                            <span
                                data-relation-role
                                class="text-xs text-muted-foreground"
                            >
                                {{ roleLabel(group) }}
                            </span>
                        </div>

                        <Combobox
                            v-if="group.acceptsOne"
                            :model-value="singleValue(group)"
                            :options="singleOptions(group)"
                            :disabled="isLocked(group)"
                            :server-search="serverSearch(group)"
                            :placeholder="
                                t(
                                    'i18n.components.engine.records.record_relations.select_record',
                                )
                            "
                            :search-placeholder="
                                t(
                                    'i18n.components.engine.records.record_relations.search_by_name_or_number',
                                )
                            "
                            :empty-label="
                                t(
                                    'i18n.components.engine.records.record_relations.there_are_no_records_available_to_link',
                                )
                            "
                            :aria-label="`${group.objectTypeName} ${roleLabel(group)}`"
                            @update:model-value="onSingleChange(group, $event)"
                            @search="onOptionsSearch(group, $event)"
                            @load-more="onOptionsLoadMore(group)"
                        />

                        <MultiSelect
                            v-else
                            :model-value="selectedIds(group)"
                            :options="optionsFor(group)"
                            :disabled="isLocked(group)"
                            :server-search="serverSearch(group)"
                            :placeholder="
                                t(
                                    'i18n.components.engine.records.record_relations.select_records',
                                )
                            "
                            :search-placeholder="
                                t(
                                    'i18n.components.engine.records.record_relations.search_by_name_or_number',
                                )
                            "
                            :empty-label="
                                t(
                                    'i18n.components.engine.records.record_relations.there_are_no_records_available_to_link',
                                )
                            "
                            :aria-label="`${group.objectTypeName} ${roleLabel(group)}`"
                            @update:model-value="onMultiChange(group, $event)"
                            @search="onOptionsSearch(group, $event)"
                            @load-more="onOptionsLoadMore(group)"
                        >
                            <template #trigger>
                                <Button
                                    type="button"
                                    variant="outline"
                                    role="combobox"
                                    :disabled="isLocked(group)"
                                    :aria-label="`${group.objectTypeName} ${roleLabel(group)}`"
                                    class="h-8 w-full justify-between gap-2 font-normal"
                                    data-relation-trigger
                                >
                                    <span
                                        class="truncate"
                                        :class="
                                            group.entriesTotal === 0
                                                ? 'text-muted-foreground'
                                                : undefined
                                        "
                                    >
                                        {{ summaryLabel(group) }}
                                    </span>
                                    <ChevronDown
                                        class="size-4 shrink-0 opacity-50"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </template>
                        </MultiSelect>
                    </div>

                    <InputError
                        v-if="error"
                        data-relation-error
                        :message="error"
                    />
                </CardContent>
            </CollapsibleContent>
        </Card>
    </Collapsible>
</template>
