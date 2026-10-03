<script setup lang="ts">
import { computed, ref } from 'vue';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import { useSegments } from '@/composables/useSegments';
import type { SegmentSummary } from '@/composables/useSegments';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

const { t } = useI18n();

const props = defineProps<{
    open: boolean;
    objectType: RecordObjectType;
    fields: FieldDefinition[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [segment: SegmentSummary];
}>();

const { save, error } = useSegments(props.objectType);

const filterableFields = computed<FieldDefinition[]>(() =>
    props.fields.filter((field) => field.is_filterable ?? false),
);

const name = ref<string>('');
const pendingTree = ref<FilterGroupNode | null>(null);
const saving = ref<boolean>(false);

const hasFilterConditions = computed<boolean>(
    () => (pendingTree.value?.conditions.length ?? 0) > 0,
);

const saveDisabled = computed<boolean>(
    () =>
        saving.value || name.value.trim() === '' || !hasFilterConditions.value,
);

function onApply(tree: FilterGroupNode): void {
    pendingTree.value = tree;
}

function onReset(): void {
    pendingTree.value = null;
}

function resetForm(): void {
    name.value = '';
    pendingTree.value = null;
}

function onOpenChange(value: boolean): void {
    if (!value) {
        resetForm();
        emit('update:open', false);
    }
}

async function onSave(): Promise<void> {
    if (saveDisabled.value) {
        return;
    }

    saving.value = true;

    try {
        const saved = await save({
            name: name.value.trim(),
            object_type_id: props.objectType.id,
            filter_definition: pendingTree.value,
        });

        if (saved === null) {
            return;
        }

        emit('saved', saved);
        resetForm();
        emit('update:open', false);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    t(
                        'i18n.components.engine.segment.segment_save_dialog.create_segment',
                    )
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'i18n.components.engine.segment.segment_save_dialog.create_a_segment_for',
                        )
                    }}
                    {{ objectType.name }}
                    {{
                        t(
                            'i18n.components.engine.segment.segment_save_dialog.example',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <Label for="segment-name">{{
                        t(
                            'i18n.components.engine.segment.segment_save_dialog.name',
                        )
                    }}</Label>
                    <Input
                        id="segment-name"
                        v-model="name"
                        data-segment-name
                        :placeholder="
                            t(
                                'i18n.components.engine.segment.segment_save_dialog.segment_name',
                            )
                        "
                    />
                </div>

                <FilterBuilder
                    v-if="filterableFields.length > 0"
                    :fields="filterableFields"
                    :show-actions="false"
                    @update:model-value="onApply"
                    @reset="onReset"
                />

                <p
                    v-else
                    class="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'i18n.components.engine.segment.segment_save_dialog.no_filterable_fields_are_configured_for_this_object_type',
                        )
                    }}
                </p>

                <p
                    v-if="error !== null"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ error }}
                </p>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="onOpenChange(false)">
                    {{
                        t(
                            'i18n.components.engine.segment.segment_save_dialog.cancel',
                        )
                    }}
                </Button>
                <Button
                    data-segment-save
                    :disabled="saveDisabled"
                    @click="onSave"
                >
                    {{
                        t(
                            'i18n.components.engine.segment.segment_save_dialog.save',
                        )
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
