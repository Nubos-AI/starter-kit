<script setup lang="ts">
import { computed } from 'vue';
import SimpleTable from '@/components/data-grid/SimpleTable.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { IMPORT_COLUMN_FORMAT_LABELS } from '@/composables/useImportWizard';
import type { ImportColumnFormat } from '@/composables/useImportWizard';

const { t } = useI18n();

const NONE = '__none__';

const props = defineProps<{
    headers: string[];
    columns: Record<string, string>;
    formats: Record<string, ImportColumnFormat>;
}>();

const emit = defineEmits<{
    'update:formats': [formats: Record<string, ImportColumnFormat>];
}>();

const formatOptions = computed<
    Array<{ value: ImportColumnFormat; label: string }>
>(() =>
    (
        Object.entries(IMPORT_COLUMN_FORMAT_LABELS) as Array<
            [ImportColumnFormat, string]
        >
    ).map(([value, label]) => ({ value, label })),
);

const mappedHeaders = computed<string[]>(() =>
    props.headers.filter((header) => {
        const target = props.columns[header];

        return typeof target === 'string' && target !== '';
    }),
);

function formatFor(header: string): string {
    return props.formats[header] ?? NONE;
}

function onFormatChange(header: string, value: string): void {
    const formats = { ...props.formats };

    if (value === NONE) {
        delete formats[header];
    } else {
        formats[header] = value as ImportColumnFormat;
    }

    emit('update:formats', formats);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <h3 class="text-sm font-semibold">
            {{
                t(
                    'i18n.components.engine.import.column_format_override.override_formats_optional',
                )
            }}
        </h3>

        <p
            v-if="mappedHeaders.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'i18n.components.engine.import.column_format_override.none_of_the_columns_is_mapped_to_a_target',
                )
            }}
        </p>

        <div v-else class="overflow-x-auto">
            <SimpleTable>
                <thead>
                    <tr>
                        <th>
                            {{
                                t(
                                    'i18n.components.engine.import.column_format_override.column',
                                )
                            }}
                        </th>
                        <th>
                            {{
                                t(
                                    'i18n.components.engine.import.column_format_override.format',
                                )
                            }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="header in mappedHeaders"
                        :key="header"
                        class="border-t"
                    >
                        <td class="font-mono text-xs">
                            {{ header }}
                        </td>
                        <td>
                            <Select
                                :model-value="formatFor(header)"
                                @update:model-value="
                                    onFormatChange(header, String($event))
                                "
                            >
                                <SelectTrigger class="w-72">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.engine.import.column_format_override.automatic',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="NONE">
                                        {{
                                            t(
                                                'i18n.components.engine.import.column_format_override.detect_automatically',
                                            )
                                        }}
                                    </SelectItem>
                                    <SelectItem
                                        v-for="option in formatOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </td>
                    </tr>
                </tbody>
            </SimpleTable>
        </div>
    </div>
</template>
