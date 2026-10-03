<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import CreateButton from '@/components/CreateButton.vue';
import ObjectTypeFieldDrawer from '@/components/engine/objectType/ObjectTypeFieldDrawer.vue';
import ObjectTypeFieldGrid from '@/components/engine/objectType/ObjectTypeFieldGrid.vue';
import ObjectTypeFieldGroupsCard from '@/components/engine/objectType/ObjectTypeFieldGroupsCard.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { useObjectTypeSection } from '@/composables/useObjectTypeSection';
import type { FieldGroupRow } from '@/types/fieldGroups';
import type { FieldTypeOption } from '@/types/fields';
import type { ObjectTypeFieldRow } from '@/types/formulas';
import type { ObjectTypeSummary } from '@/types/objectTypes';
import type { RollupTargetOption } from '@/types/rollups';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    objectType: ObjectTypeSummary;
    fields: ObjectTypeFieldRow[];
    fieldGroups: FieldGroupRow[];
    fieldTypes: FieldTypeOption[];
    objectTypeOptions: SelectOption[];
    rollupTargets: RollupTargetOption[];
}>();

const { isEditable } = useObjectTypeSection(props.objectType, 'fields');

const fieldDrawerOpen = ref<boolean>(false);
const editingField = ref<ObjectTypeFieldRow | null>(null);

function openFieldCreate(): void {
    editingField.value = null;
    fieldDrawerOpen.value = true;
}

function openFieldEdit(field: ObjectTypeFieldRow): void {
    editingField.value = field;
    fieldDrawerOpen.value = true;
}
</script>

<template>
    <Head
        :title="
            t('i18n.pages.object_types.edit.fields.fields_2', {
                value1: objectType.name,
            })
        "
    />

    <ObjectTypeFieldGroupsCard
        :object-type-slug="objectType.slug"
        :groups="fieldGroups"
        :editable="isEditable"
    />

    <Card>
        <CardHeader class="flex flex-row items-start justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <CardTitle>{{
                    t('i18n.pages.object_types.edit.fields.fields')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.object_types.edit.fields.field_definitions_describe_this_object_type_s_records_edit',
                        )
                    }}
                </CardDescription>
            </div>
            <CreateButton
                v-if="isEditable"
                size="sm"
                :label="t('i18n.pages.object_types.edit.fields.new_field')"
                @click="openFieldCreate"
            />
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <ObjectTypeFieldGrid
                :object-type-slug="objectType.slug"
                :fields="fields"
                :field-types="fieldTypes"
                :groups="fieldGroups"
                :editable="isEditable"
                @edit-field="openFieldEdit"
            />

            <p v-if="fields.length === 0" class="text-sm text-muted-foreground">
                {{
                    t(
                        'i18n.pages.object_types.edit.fields.no_fields_defined_yet',
                    )
                }}
            </p>
        </CardContent>
    </Card>

    <ObjectTypeFieldDrawer
        v-if="isEditable"
        v-model:open="fieldDrawerOpen"
        :object-type-slug="objectType.slug"
        :field-types="fieldTypes"
        :object-type-options="objectTypeOptions"
        :groups="fieldGroups"
        :mode="editingField === null ? 'create' : 'edit'"
        :field="editingField"
        :fields="fields"
        :rollup-targets="rollupTargets"
    />
</template>
