<script setup lang="ts">
import { computed, ref } from 'vue';
import SimpleTable from '@/components/data-grid/SimpleTable.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
import { importMappingTargets } from '@/composables/useImportWizard';
import type {
    ImportDuplicateMode,
    ImportMappingPreset,
    ImportMissingOptionMode,
    MappingState,
} from '@/composables/useImportWizard';
import type { FieldDefinition } from '@/types/fields';

const { t } = useI18n();

const UNMAPPED = '__unmapped__';

const props = defineProps<{
    headers: string[];
    fields: FieldDefinition[];
    mapping: MappingState;
    duplicateMode: ImportDuplicateMode;
    missingOptionMode: ImportMissingOptionMode;
    presets: ImportMappingPreset[];
}>();

const emit = defineEmits<{
    'update:mapping': [mapping: Partial<MappingState>];
    'update:duplicateMode': [mode: ImportDuplicateMode];
    'update:missingOptionMode': [mode: ImportMissingOptionMode];
    autoMatch: [];
    savePreset: [name: string];
    loadPreset: [preset: ImportMappingPreset];
}>();

const duplicateModes: Array<{ value: ImportDuplicateMode; label: string }> = [
    {
        value: 'skip',
        label: t('i18n.components.engine.import.mapping_step.skip_duplicates'),
    },
    {
        value: 'upsert',
        label: t('i18n.components.engine.import.mapping_step.update_upsert'),
    },
    {
        value: 'insert',
        label: t(
            'i18n.components.engine.import.mapping_step.always_create_new',
        ),
    },
];

const missingOptionModes: Array<{
    value: ImportMissingOptionMode;
    label: string;
}> = [
    {
        value: 'error',
        label: t(
            'i18n.components.engine.import.mapping_step.reject_rows_with_missing_selection_options',
        ),
    },
    {
        value: 'create',
        label: t(
            'i18n.components.engine.import.mapping_step.create_missing_selection_options',
        ),
    },
];

const targets = computed(() => importMappingTargets(props.fields));

const presetDialogOpen = ref<boolean>(false);
const presetName = ref<string>('');

function targetFor(header: string): string {
    return props.mapping.columns[header] ?? UNMAPPED;
}

function onTargetChange(header: string, value: string): void {
    const columns = { ...props.mapping.columns };

    if (value === UNMAPPED) {
        delete columns[header];
    } else {
        columns[header] = value;
    }

    emit('update:mapping', { columns });
}

function onSavePreset(): void {
    if (presetName.value.trim() === '') {
        return;
    }

    emit('savePreset', presetName.value.trim());
    presetName.value = '';
    presetDialogOpen.value = false;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">
                {{
                    t('i18n.components.engine.import.mapping_step.map_columns')
                }}
            </h3>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" @click="emit('autoMatch')">
                    {{
                        t(
                            'i18n.components.engine.import.mapping_step.map_automatically',
                        )
                    }}
                </Button>
                <Select
                    v-if="presets.length > 0"
                    @update:model-value="
                        (value) => {
                            const preset = presets.find(
                                (entry) => entry.id === String(value),
                            );
                            if (preset) {
                                emit('loadPreset', preset);
                            }
                        }
                    "
                >
                    <SelectTrigger class="w-48">
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.components.engine.import.mapping_step.load_template',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="preset in presets"
                            :key="preset.id"
                            :value="preset.id"
                        >
                            {{ preset.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Dialog v-model:open="presetDialogOpen">
                    <DialogTrigger as-child>
                        <Button variant="outline" size="sm">
                            {{
                                t(
                                    'i18n.components.engine.import.mapping_step.save_as_template',
                                )
                            }}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{{
                                t(
                                    'i18n.components.engine.import.mapping_step.save_mapping_as_template',
                                )
                            }}</DialogTitle>
                        </DialogHeader>
                        <div class="flex flex-col gap-2">
                            <Label for="preset-name">{{
                                t(
                                    'i18n.components.engine.import.mapping_step.template_name',
                                )
                            }}</Label>
                            <Input
                                id="preset-name"
                                v-model="presetName"
                                :placeholder="
                                    t(
                                        'i18n.components.engine.import.mapping_step.e_g_standard_contacts',
                                    )
                                "
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                :disabled="presetName.trim() === ''"
                                @click="onSavePreset"
                            >
                                {{
                                    t(
                                        'i18n.components.engine.import.mapping_step.save',
                                    )
                                }}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <div class="overflow-x-auto">
            <SimpleTable>
                <thead>
                    <tr>
                        <th>
                            {{
                                t(
                                    'i18n.components.engine.import.mapping_step.file_column',
                                )
                            }}
                        </th>
                        <th>
                            {{
                                t(
                                    'i18n.components.engine.import.mapping_step.target_field',
                                )
                            }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="header in headers"
                        :key="header"
                        class="border-t"
                    >
                        <td class="font-mono text-xs">
                            {{ header }}
                        </td>
                        <td>
                            <Select
                                :model-value="targetFor(header)"
                                @update:model-value="
                                    onTargetChange(header, String($event))
                                "
                            >
                                <SelectTrigger class="w-full">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.engine.import.mapping_step.do_not_map',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="UNMAPPED">
                                        {{
                                            t(
                                                'i18n.components.engine.import.mapping_step.do_not_map',
                                            )
                                        }}
                                    </SelectItem>
                                    <SelectItem
                                        v-for="target in targets"
                                        :key="target.value"
                                        :value="target.value"
                                    >
                                        {{ target.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </td>
                    </tr>
                </tbody>
            </SimpleTable>
        </div>

        <div class="flex flex-wrap gap-4">
            <div class="flex flex-col gap-1">
                <Label for="duplicate-mode">{{
                    t(
                        'i18n.components.engine.import.mapping_step.duplicate_handling',
                    )
                }}</Label>
                <Select
                    :model-value="duplicateMode"
                    @update:model-value="
                        emit(
                            'update:duplicateMode',
                            $event as ImportDuplicateMode,
                        )
                    "
                >
                    <SelectTrigger id="duplicate-mode" class="w-72">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="mode in duplicateModes"
                            :key="mode.value"
                            :value="mode.value"
                        >
                            {{ mode.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="max-w-72 text-xs text-muted-foreground">
                    {{
                        t(
                            'i18n.components.engine.import.mapping_step.the_import_detects_duplicates_using_the_external_reference_id',
                        )
                    }}
                </p>
            </div>
            <div class="flex flex-col gap-1">
                <Label for="missing-option-mode">{{
                    t(
                        'i18n.components.engine.import.mapping_step.missing_selection_options',
                    )
                }}</Label>
                <Select
                    :model-value="missingOptionMode"
                    @update:model-value="
                        emit(
                            'update:missingOptionMode',
                            $event as ImportMissingOptionMode,
                        )
                    "
                >
                    <SelectTrigger id="missing-option-mode" class="w-72">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="mode in missingOptionModes"
                            :key="mode.value"
                            :value="mode.value"
                        >
                            {{ mode.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>
    </div>
</template>
