<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { supportsRelationTarget } from '@/types/fieldEditor';
import type { FieldType } from '@/types/fields';
import type { RollupTargetOption } from '@/types/rollups';

const { t } = useI18n();

const props = defineProps<{
    fieldType: FieldType;
    relationshipTypes: RollupTargetOption[];
    initialRelationshipTypeId: string;
    errorMessage?: string;
}>();

const controlId = useId();

const relationshipTypeId = ref<string>(props.initialRelationshipTypeId);

const isVisible = computed<boolean>(() =>
    supportsRelationTarget(props.fieldType),
);
</script>

<template>
    <div v-if="isVisible" data-testid="relation-config" class="grid gap-2">
        <Label :for="`${controlId}_relationship`">{{
            t(
                'i18n.components.engine.object_type.relation_field_editor.relationship_type',
            )
        }}</Label>
        <Select
            v-model="relationshipTypeId"
            name="config[relationship_type_id]"
        >
            <SelectTrigger :id="`${controlId}_relationship`" class="w-full">
                <SelectValue
                    :placeholder="
                        t(
                            'i18n.components.engine.object_type.relation_field_editor.select_relationship_type',
                        )
                    "
                />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="option in props.relationshipTypes"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </SelectItem>
            </SelectContent>
        </Select>
        <p
            v-if="props.relationshipTypes.length === 0"
            class="text-xs text-muted-foreground"
        >
            {{
                t(
                    'i18n.components.engine.object_type.relation_field_editor.this_object_type_has_no_relationship_type_pointing_to',
                )
            }}
        </p>
        <p v-else class="text-xs text-muted-foreground">
            {{
                t(
                    'i18n.components.engine.object_type.relation_field_editor.the_field_shows_records_linked_through_this_relationship_type',
                )
            }}
        </p>
        <InputError :message="props.errorMessage" />
    </div>
</template>
