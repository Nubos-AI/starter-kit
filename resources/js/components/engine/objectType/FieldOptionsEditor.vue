<script setup lang="ts">
import { ChevronDown, ChevronUp, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { supportsOptions } from '@/types/fieldEditor';
import type { FieldType } from '@/types/fields';

const { t } = useI18n();

type OptionSource = 'options' | 'lookup';

const props = defineProps<{
    fieldType: FieldType;
    initialOptions: string[];
    initialLookupObjectType: string;
    objectTypeOptions: Array<{ value: string; label: string }>;
    errorMessage?: string;
}>();

const controlId = useId();

const source = ref<OptionSource>(
    props.initialLookupObjectType === '' ? 'options' : 'lookup',
);
const options = ref<string[]>([...props.initialOptions]);
const lookupObjectType = ref<string>(props.initialLookupObjectType);

const isVisible = computed<boolean>(() => supportsOptions(props.fieldType));

const usesLookup = computed<boolean>(() => source.value === 'lookup');

const submittedOptions = computed<string[]>(() =>
    options.value
        .map((option) => option.trim())
        .filter((option) => option !== ''),
);

watch(
    () => props.fieldType,
    () => {
        if (!isVisible.value) {
            return;
        }

        if (options.value.length === 0 && !usesLookup.value) {
            options.value = [''];
        }
    },
    { immediate: true },
);

function addOption(): void {
    options.value = [...options.value, ''];
}

function removeOption(index: number): void {
    options.value = options.value.filter((_, current) => current !== index);
}

function moveOption(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= options.value.length) {
        return;
    }

    const next = [...options.value];
    const [moved] = next.splice(index, 1);

    next.splice(target, 0, moved);
    options.value = next;
}
</script>

<template>
    <div v-if="isVisible" data-testid="field-options-editor" class="grid gap-4">
        <div class="grid gap-2">
            <Label :for="`${controlId}_source`">{{
                t(
                    'i18n.components.engine.object_type.field_options_editor.selection_source',
                )
            }}</Label>
            <Select v-model="source">
                <SelectTrigger :id="`${controlId}_source`" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.object_type.field_options_editor.select_source',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="options">{{
                        t(
                            'i18n.components.engine.object_type.field_options_editor.custom_options',
                        )
                    }}</SelectItem>
                    <SelectItem value="lookup">
                        {{
                            t(
                                'i18n.components.engine.object_type.field_options_editor.records_of_an_object_type',
                            )
                        }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div v-if="usesLookup" class="grid gap-2">
            <Label :for="`${controlId}_lookup`">{{
                t(
                    'i18n.components.engine.object_type.field_options_editor.object_type',
                )
            }}</Label>
            <Select
                v-model="lookupObjectType"
                name="config[lookup_object_type]"
            >
                <SelectTrigger :id="`${controlId}_lookup`" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.object_type.field_options_editor.select_object_type',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in props.objectTypeOptions"
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
                        'i18n.components.engine.object_type.field_options_editor.the_selection_shows_records_of_this_object_type_instead',
                    )
                }}
            </p>
        </div>

        <div v-else class="grid gap-2">
            <span class="text-sm leading-none font-medium">{{
                t(
                    'i18n.components.engine.object_type.field_options_editor.options',
                )
            }}</span>
            <div
                v-for="(option, index) in options"
                :key="index"
                class="flex items-center gap-2"
            >
                <Input
                    v-model="options[index]"
                    :aria-label="
                        t(
                            'i18n.components.engine.object_type.field_options_editor.option',
                            { value1: index + 1 },
                        )
                    "
                    :data-testid="`option-input-${index}`"
                    :placeholder="
                        t(
                            'i18n.components.engine.object_type.field_options_editor.e_g_high',
                        )
                    "
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="index === 0"
                    :aria-label="
                        t(
                            'i18n.components.engine.object_type.field_options_editor.move_option_up',
                            { value1: index + 1 },
                        )
                    "
                    @click="moveOption(index, -1)"
                >
                    <ChevronUp />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="index === options.length - 1"
                    :aria-label="
                        t(
                            'i18n.components.engine.object_type.field_options_editor.move_option_down',
                            { value1: index + 1 },
                        )
                    "
                    @click="moveOption(index, 1)"
                >
                    <ChevronDown />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="
                        t(
                            'i18n.components.engine.object_type.field_options_editor.remove_option',
                            { value1: index + 1 },
                        )
                    "
                    :data-testid="`option-remove-${index}`"
                    @click="removeOption(index)"
                >
                    <Trash2 />
                </Button>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="justify-self-start"
                data-testid="option-add"
                @click="addOption"
            >
                <Plus />
                {{
                    t(
                        'i18n.components.engine.object_type.field_options_editor.add_option',
                    )
                }}
            </Button>
            <input
                v-for="(option, index) in submittedOptions"
                :key="`submitted-${index}`"
                type="hidden"
                :name="`config[options][${index}]`"
                :value="option"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.object_type.field_options_editor.an_option_in_use_can_only_be_removed_once',
                    )
                }}
            </p>
        </div>

        <InputError :message="props.errorMessage" />
    </div>
</template>
