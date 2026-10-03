<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    fieldInputType,
    numericInputStep,
} from '@/composables/useFieldTypeRegistry';
import { useI18n } from '@/composables/useI18n';
import type {
    FieldDefaultKind,
    FieldDefaultSource,
    FieldDefaultValue,
} from '@/types/fieldEditor';
import {
    fieldDefaultKindOptions,
    fieldDefaultSourceOptions,
    supportsDefaultValue,
} from '@/types/fieldEditor';
import type { FieldType } from '@/types/fields';

const { t } = useI18n();

const props = defineProps<{
    fieldType: FieldType;
    initialDefault: FieldDefaultValue | null;
    errorMessage?: string;
}>();

const controlId = useId();

const kind = ref<FieldDefaultKind>(props.initialDefault?.kind ?? 'none');
const staticValue = ref<string>(
    props.initialDefault?.value === undefined ||
        props.initialDefault?.value === null
        ? ''
        : String(props.initialDefault.value),
);
const source = ref<FieldDefaultSource>(props.initialDefault?.source ?? 'today');

const isVisible = computed<boolean>(() =>
    supportsDefaultValue(props.fieldType),
);

const inputType = computed<string>(() => fieldInputType(props.fieldType));

const inputStep = computed<string | undefined>(() =>
    numericInputStep(props.fieldType),
);
</script>

<template>
    <div v-if="isVisible" data-testid="field-default-editor" class="grid gap-4">
        <div class="grid gap-2">
            <Label :for="`${controlId}_kind`">{{
                t(
                    'i18n.components.engine.object_type.field_default_value_editor.default_value',
                )
            }}</Label>
            <Select v-model="kind">
                <SelectTrigger :id="`${controlId}_kind`" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.object_type.field_default_value_editor.select_default_value',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in fieldDefaultKindOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <input
                v-if="kind !== 'none'"
                type="hidden"
                name="default_value[kind]"
                :value="kind"
            />
        </div>

        <div v-if="kind === 'static'" class="grid gap-2">
            <Label :for="`${controlId}_value`">{{
                t(
                    'i18n.components.engine.object_type.field_default_value_editor.fixed_value',
                )
            }}</Label>
            <Input
                :id="`${controlId}_value`"
                v-model="staticValue"
                name="default_value[value]"
                :type="inputType"
                :step="inputStep"
                data-testid="default-static-value"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.object_type.field_default_value_editor.this_value_is_inserted_on_creation_if_the_field',
                    )
                }}
            </p>
        </div>

        <div v-if="kind === 'dynamic'" class="grid gap-2">
            <Label :for="`${controlId}_source`">{{
                t(
                    'i18n.components.engine.object_type.field_default_value_editor.source',
                )
            }}</Label>
            <Select v-model="source" name="default_value[source]">
                <SelectTrigger :id="`${controlId}_source`" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.object_type.field_default_value_editor.select_source',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in fieldDefaultSourceOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.object_type.field_default_value_editor.the_value_is_generated_when_the_record_is_created',
                    )
                }}
            </p>
        </div>

        <InputError :message="props.errorMessage" />
    </div>
</template>
