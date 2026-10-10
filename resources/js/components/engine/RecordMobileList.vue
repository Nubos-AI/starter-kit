<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import RecordGridsController from '@/actions/App/Http/Controllers/Engine/RecordGridsController';
import RecordWriteController from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import AgingBadge from '@/components/engine/AgingBadge.vue';
import {
    isComplexField,
    isInlineEditable,
} from '@/components/engine/cellEditors';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    fieldInputType,
    normalizeFieldOptions,
    numericInputStep,
} from '@/composables/useFieldTypeRegistry';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorResponse, toFieldErrors } from '@/lib/errorResponse';
import { extractRecord } from '@/lib/recordPayload';
import { recordToast } from '@/lib/recordToast';
import type { FieldDefinition, FieldType } from '@/types/fields';
import type { GridColumn } from '@/types/grid';
import type { RecordObjectType, RecordPayload } from '@/types/records';

const { t } = useI18n();

const props = defineProps<{
    objectType: RecordObjectType;
    columns: GridColumn[];
    fieldDefinitions: FieldDefinition[];
    segmentId?: string | null;
    search?: string | null;
    pageSize?: number;
}>();

const { canForObjectType } = usePermissions();

const emit = defineEmits<{
    openRecord: [record: RecordPayload];
    state: [value: 'loading' | 'error' | 'empty' | 'ready'];
}>();

const DEFAULT_PAGE_SIZE = 25;
const pageSize = computed<number>(() => props.pageSize ?? DEFAULT_PAGE_SIZE);
const REQUEST_TIMEOUT_MS = 15000;

const LOAD_ERROR_MESSAGE = t(
    'i18n.components.engine.record_mobile_list.the_records_could_not_be_loaded_please_try_again',
);
const SAVE_SUCCESS_MESSAGE = t(
    'i18n.components.engine.record_mobile_list.change_saved',
);
const SAVE_CONFLICT_MESSAGE = t(
    'i18n.components.engine.record_mobile_list.the_data_has_changed_in_the_meantime_and_your',
);
const SAVE_ERROR_MESSAGE = t(
    'i18n.components.engine.record_mobile_list.the_change_could_not_be_saved_and_was_reverted',
);
const records = ref<RecordPayload[]>([]);
const loading = ref<boolean>(true);
const loadingMore = ref<boolean>(false);
const error = ref<string | null>(null);
const hasMore = ref<boolean>(true);
const nextStart = ref<number>(0);
const announcement = ref<string>('');

const editing = ref<{ recordId: string; fieldKey: string } | null>(null);
const draft = ref<unknown>(null);
const saving = ref<boolean>(false);

const definitionsByKey = new Map(
    props.fieldDefinitions.map((definition) => [definition.key, definition]),
);

const liveMessage = computed(() => announcement.value);

const orderedColumns = computed<GridColumn[]>(() =>
    [...props.columns].sort(
        (first, second) => first.list_position - second.list_position,
    ),
);

const announce = async (message: string): Promise<void> => {
    announcement.value = '';
    await nextTick();
    announcement.value = message;
};

const emitState = (): void => {
    if (error.value !== null) {
        emit('state', 'error');

        return;
    }

    if (loading.value) {
        emit('state', 'loading');

        return;
    }

    const isEmpty = records.value.length === 0;

    emit('state', isEmpty ? 'empty' : 'ready');
};

const optionLabels = (fieldKey: string): Map<string, string> => {
    const options = normalizeFieldOptions(
        definitionsByKey.get(fieldKey)?.config?.options,
    );

    return new Map(options.map((option) => [option.value, option.label]));
};

const fieldOptions = (
    fieldKey: string,
): Array<{ value: string; label: string }> =>
    normalizeFieldOptions(definitionsByKey.get(fieldKey)?.config?.options);

const formatValue = (column: GridColumn, value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (column.field_type === 'boolean') {
        return value === true
            ? t('i18n.components.engine.record_mobile_list.yes')
            : t('i18n.components.engine.record_mobile_list.no');
    }

    if (column.field_type === 'single_select') {
        const key = String(value);

        return optionLabels(column.key).get(key) ?? key;
    }

    if (Array.isArray(value)) {
        const labels = optionLabels(column.key);

        return value
            .map((entry) => labels.get(String(entry)) ?? String(entry))
            .join(', ');
    }

    return String(value);
};

const loadGrid = async (append: boolean): Promise<void> => {
    if (append) {
        loadingMore.value = true;
    } else {
        loading.value = true;
        nextStart.value = 0;
        hasMore.value = true;
    }

    error.value = null;
    emitState();

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
        const response = await fetch(
            RecordGridsController.grid.url({
                objectType: props.objectType.slug,
            }),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders(),
                body: JSON.stringify({
                    startRow: nextStart.value,
                    endRow: nextStart.value + pageSize.value,
                    sortModel: [],
                    filterModel: {},
                    search: props.search ?? null,
                    segment: props.segmentId ?? null,
                }),
                signal: controller.signal,
            },
        );

        if (!response.ok) {
            throw new Error(`Grid endpoint responded with ${response.status}`);
        }

        const payload = (await response.json()) as {
            rows: RecordPayload[];
            lastRow: number | null;
        };

        records.value = append
            ? [...records.value, ...payload.rows]
            : payload.rows;
        nextStart.value += payload.rows.length;

        const reachedEnd =
            payload.rows.length < pageSize.value ||
            (payload.lastRow !== null &&
                payload.lastRow >= 0 &&
                records.value.length >= payload.lastRow);

        hasMore.value = !reachedEnd;
    } catch {
        error.value = LOAD_ERROR_MESSAGE;
    } finally {
        clearTimeout(timeout);
        loading.value = false;
        loadingMore.value = false;
        emitState();
    }
};

const reload = (): void => {
    void loadGrid(false);
};

const loadMore = (): void => {
    if (!hasMore.value || loadingMore.value) {
        return;
    }

    void loadGrid(true);
};

const isEditing = (recordId: string, fieldKey: string): boolean =>
    editing.value?.recordId === recordId &&
    editing.value?.fieldKey === fieldKey;

const startEdit = (record: RecordPayload, column: GridColumn): void => {
    if (saving.value) {
        return;
    }

    editing.value = { recordId: record.id, fieldKey: column.key };
    draft.value = record.data[column.key] ?? null;
};

const cancelEdit = (): void => {
    editing.value = null;
    draft.value = null;
};

const coerce = (fieldType: FieldType, value: unknown): unknown => {
    if (fieldType === 'boolean') {
        return value === true;
    }

    if (
        (fieldType === 'number' ||
            fieldType === 'decimal' ||
            fieldType === 'money') &&
        value !== null &&
        value !== ''
    ) {
        const parsed = Number(value);

        return Number.isNaN(parsed) ? value : parsed;
    }

    return value === '' ? null : value;
};

const commitEdit = async (
    record: RecordPayload,
    column: GridColumn,
): Promise<void> => {
    if (editing.value === null || saving.value) {
        return;
    }

    const value = coerce(column.field_type, draft.value);

    if (value === (record.data[column.key] ?? null)) {
        cancelEdit();

        return;
    }

    saving.value = true;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
        const response = await fetch(
            RecordWriteController.updateCell.url({ record: record.id }),
            {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify({
                    field: column.key,
                    value,
                    version: record.version,
                }),
                signal: controller.signal,
            },
        );

        if (response.status === 200) {
            replaceRecord(extractRecord(await response.json()));
            cancelEdit();
            recordToast('edit');
            void announce(SAVE_SUCCESS_MESSAGE);

            return;
        }

        if (response.status === 409) {
            replaceRecord(extractRecord(await response.json()));
            cancelEdit();
            toast.error(SAVE_CONFLICT_MESSAGE);
            void announce(SAVE_CONFLICT_MESSAGE);

            return;
        }

        const failure = await readErrorResponse(response);
        const reason =
            toFieldErrors(failure.errors)[column.key] ??
            failure.message ??
            SAVE_ERROR_MESSAGE;

        cancelEdit();
        toast.error(reason);
        void announce(reason);
    } catch {
        cancelEdit();
        toast.error(SAVE_ERROR_MESSAGE);
        void announce(SAVE_ERROR_MESSAGE);
    } finally {
        clearTimeout(timeout);
        saving.value = false;
    }
};

const replaceRecord = (fresh: RecordPayload): void => {
    const index = records.value.findIndex((entry) => entry.id === fresh.id);

    if (index !== -1) {
        records.value.splice(index, 1, fresh);
    }
};

const onFieldTap = (record: RecordPayload, column: GridColumn): void => {
    if (isComplexField(column.field_type)) {
        emit('openRecord', record);

        return;
    }

    if (
        canForObjectType(props.objectType.slug, 'update') &&
        isInlineEditable(column.field_type)
    ) {
        startEdit(record, column);

        return;
    }

    emit('openRecord', record);
};

const recordTitle = (record: RecordPayload): string =>
    record.recordNumber ??
    t('i18n.components.engine.record_mobile_list.record');

defineExpose({ reload });

onMounted(() => {
    reload();
});
</script>

<template>
    <section
        class="flex h-full w-full flex-col gap-3 overflow-y-auto p-4"
        :aria-label="
            t('i18n.components.engine.record_mobile_list.list', {
                value1: objectType.name,
            })
        "
    >
        <div
            role="status"
            aria-live="polite"
            aria-atomic="true"
            class="sr-only"
        >
            {{ liveMessage }}
        </div>

        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>

        <div v-if="loading" class="flex flex-col gap-3" aria-hidden="true">
            <Skeleton
                v-for="placeholder in 4"
                :key="placeholder"
                class="h-28 w-full"
            />
        </div>

        <template v-else>
            <Card v-for="record in records" :key="record.id" class="gap-3 py-3">
                <CardHeader class="px-4">
                    <CardTitle class="text-sm leading-tight">
                        <Button
                            type="button"
                            variant="link"
                            class="h-auto justify-start p-0 text-left text-inherit no-underline hover:underline focus-visible:underline"
                            @click="emit('openRecord', record)"
                        >
                            {{ recordTitle(record) }}
                        </Button>
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3 px-4">
                    <AgingBadge :aging="record.aging ?? null" />

                    <div
                        v-for="column in orderedColumns"
                        :key="column.key"
                        class="flex flex-col gap-1"
                    >
                        <span class="text-xs text-muted-foreground">
                            {{ column.label }}
                        </span>

                        <template v-if="isEditing(record.id, column.key)">
                            <Checkbox
                                v-if="column.field_type === 'boolean'"
                                :model-value="draft === true"
                                :aria-label="column.label"
                                @update:model-value="
                                    (value) => {
                                        draft = value === true;
                                        void commitEdit(record, column);
                                    }
                                "
                            />
                            <Select
                                v-else-if="
                                    column.field_type === 'single_select'
                                "
                                :model-value="(draft as string) ?? ''"
                                @update:model-value="
                                    (value) => {
                                        draft = (value as string) ?? null;
                                        void commitEdit(record, column);
                                    }
                                "
                            >
                                <SelectTrigger
                                    class="w-full"
                                    :aria-label="column.label"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.engine.record_mobile_list.select',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in fieldOptions(
                                            column.key,
                                        )"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Input
                                v-else
                                :model-value="
                                    (draft as string | number | null) ?? ''
                                "
                                :type="fieldInputType(column.field_type)"
                                :step="numericInputStep(column.field_type)"
                                :aria-label="column.label"
                                :disabled="saving"
                                autofocus
                                @update:model-value="(value) => (draft = value)"
                                @keydown.enter.prevent="
                                    commitEdit(record, column)
                                "
                                @keydown.esc.prevent="cancelEdit"
                                @blur="commitEdit(record, column)"
                            />
                        </template>

                        <Button
                            v-else
                            type="button"
                            variant="ghost"
                            class="h-auto justify-start px-1 py-0.5 text-left font-normal"
                            @click="onFieldTap(record, column)"
                        >
                            {{ formatValue(column, record.data[column.key]) }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Button
                v-if="hasMore && records.length > 0"
                variant="outline"
                :disabled="loadingMore"
                @click="loadMore"
            >
                {{ t('i18n.components.engine.record_mobile_list.load_more') }}
            </Button>
        </template>
    </section>
</template>

<style scoped>
@media (prefers-reduced-motion: reduce) {
    * {
        transition: none !important;
    }
}
</style>
