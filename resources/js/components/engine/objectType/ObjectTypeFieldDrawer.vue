<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FieldDefinitionsController from '@/actions/App/Http/Controllers/Engine/FieldDefinitionsController';
import FieldTypesController from '@/actions/App/Http/Controllers/Engine/FieldTypesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FieldDefaultValueEditor from '@/components/engine/objectType/FieldDefaultValueEditor.vue';
import FieldIndexingSection from '@/components/engine/objectType/FieldIndexingSection.vue';
import FieldOptionsEditor from '@/components/engine/objectType/FieldOptionsEditor.vue';
import FieldRecomputeAction from '@/components/engine/objectType/FieldRecomputeAction.vue';
import FieldTypePicker from '@/components/engine/objectType/FieldTypePicker.vue';
import FieldValidationRulesEditor from '@/components/engine/objectType/FieldValidationRulesEditor.vue';
import FormulaFieldEditor from '@/components/engine/objectType/FormulaFieldEditor.vue';
import RelationFieldEditor from '@/components/engine/objectType/RelationFieldEditor.vue';
import RollupFieldEditor from '@/components/engine/objectType/RollupFieldEditor.vue';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import {
    toFieldOptions,
    toLookupObjectType,
    toRelationshipTypeId,
} from '@/types/fieldEditor';
import type { FieldGroupRow } from '@/types/fieldGroups';
import type { FieldType } from '@/types/fields';
import type { FieldTypeOption } from '@/types/fields';
import type { ObjectTypeFieldRow } from '@/types/formulas';
import type { RollupFieldConfig, RollupTargetOption } from '@/types/rollups';
import { toRollupFieldConfig } from '@/types/rollups';

const { t } = useI18n();

const props = defineProps<{
    open: boolean;
    objectTypeSlug: string;
    fieldTypes: FieldTypeOption[];
    objectTypeOptions: Array<{ value: string; label: string }>;
    groups: FieldGroupRow[];
    mode: 'create' | 'edit';
    field: ObjectTypeFieldRow | null;
    fields: ObjectTypeFieldRow[];
    rollupTargets: RollupTargetOption[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const reservedKeyHint = t(
    'i18n.components.engine.object_type.object_type_field_drawer.this_field_is_part_of_the_object_type_s',
);

const immutableKeyHint = t(
    'i18n.components.engine.object_type.object_type_field_drawer.the_key_cannot_be_changed_after_creation',
);

const lockedTypeHint = t(
    'i18n.components.engine.object_type.object_type_field_drawer.this_field_type_cannot_be_changed_after_creation',
);

const UNGROUPED_VALUE = 'none';

const selectedFieldType = ref<FieldType>(
    props.field?.field_type ?? 'text_short',
);
const selectedFieldGroup = ref<string>(
    props.field?.field_group_id ?? UNGROUPED_VALUE,
);

const submittedFieldGroup = computed<string>(() =>
    selectedFieldGroup.value === UNGROUPED_VALUE
        ? ''
        : selectedFieldGroup.value,
);

const sortedGroups = computed<FieldGroupRow[]>(() =>
    [...props.groups].sort((left, right) => left.position - right.position),
);
const submitCount = ref<number>(0);
const pendingFieldType = ref<FieldType | null>(null);
const typeChangeError = ref<string | null>(null);
const typeChangePending = ref<boolean>(false);

const isEdit = computed<boolean>(
    () => props.mode === 'edit' && props.field !== null,
);

const isReserved = computed<boolean>(() => props.field?.is_reserved === true);

const formBinding = computed(() =>
    props.mode === 'create' || props.field === null
        ? FieldDefinitionsController.store.form({
              objectType: props.objectTypeSlug,
          })
        : FieldDefinitionsController.update.form({
              objectType: props.objectTypeSlug,
              field: props.field.id,
          }),
);

const formKey = computed<string>(
    () => `${props.mode}:${props.field?.id ?? 'new'}:${submitCount.value}`,
);

const isCalculated = computed<boolean>(
    () =>
        selectedFieldType.value === 'computed' ||
        selectedFieldType.value === 'rollup',
);

const tabs = computed<Array<{ id: string; label: string }>>(() => [
    {
        id: 'general',
        label: t(
            'i18n.components.engine.object_type.object_type_field_drawer.general',
        ),
    },
    ...(isCalculated.value
        ? [
              {
                  id: 'calculation',
                  label: t(
                      'i18n.components.engine.object_type.object_type_field_drawer.calculation',
                  ),
              },
          ]
        : []),
    {
        id: 'validation',
        label: t(
            'i18n.components.engine.object_type.object_type_field_drawer.validation',
        ),
    },
    {
        id: 'behaviour',
        label: t(
            'i18n.components.engine.object_type.object_type_field_drawer.behaviour',
        ),
    },
]);

const activeTab = ref<string>('general');

const hasChosenType = ref<boolean>(props.mode === 'edit');

const isPickingType = computed<boolean>(
    () => !isEdit.value && !hasChosenType.value,
);

const chosenTypeLabel = computed<string>(
    () =>
        props.fieldTypes.find(
            (option) => option.value === selectedFieldType.value,
        )?.label ?? '',
);

function chooseFieldType(value: string): void {
    selectedFieldType.value = value as FieldType;
    activeTab.value = 'general';
    hasChosenType.value = true;
}

const generalErrorKeys: ReadonlyArray<string> = [
    'label',
    'description',
    'key',
    'field_type',
    'field_group_id',
];

function errorKeysFor(tab: string): ReadonlyArray<string> {
    if (tab === 'general') {
        return isCalculated.value
            ? generalErrorKeys
            : [...generalErrorKeys, 'config'];
    }

    if (tab === 'calculation') {
        return ['config'];
    }

    if (tab === 'validation') {
        return ['validation_rules', 'default_value'];
    }

    return ['is_encrypted'];
}

function hasError(tab: string, errors: Record<string, string>): boolean {
    return errorKeysFor(tab).some((key) => Boolean(errors[key]));
}

const isTypeLocked = computed<boolean>(
    () =>
        isReserved.value ||
        (props.field !== null && !props.field.is_type_changeable),
);

const rollupInitialConfig = computed<RollupFieldConfig | null>(() =>
    toRollupFieldConfig(props.field?.config),
);

const initialOptions = computed<string[]>(() =>
    toFieldOptions(props.field?.config),
);

const initialLookupObjectType = computed<string>(() =>
    toLookupObjectType(props.field?.config),
);

const initialRelationshipTypeId = computed<string>(() =>
    toRelationshipTypeId(props.field?.config),
);

const typeChangeDescription = computed<string>(() =>
    typeChangeError.value === null
        ? t(
              'i18n.components.engine.object_type.object_type_field_drawer.existing_values_in_this_field_will_be_converted_to',
          )
        : typeChangeError.value,
);

const typeChangeConfirmLabel = computed<string>(() =>
    typeChangeError.value === null
        ? t(
              'i18n.components.engine.object_type.object_type_field_drawer.change_type',
          )
        : t(
              'i18n.components.engine.object_type.object_type_field_drawer.change_anyway',
          ),
);

watch(
    () => [props.open, props.field?.id],
    () => {
        selectedFieldGroup.value =
            props.field?.field_group_id ?? UNGROUPED_VALUE;
        selectedFieldType.value = props.field?.field_type ?? 'text_short';
        pendingFieldType.value = null;
        typeChangeError.value = null;
        hasChosenType.value = props.mode === 'edit';
        activeTab.value = 'general';
    },
);

watch(isCalculated, (calculated) => {
    if (!calculated && activeTab.value === 'calculation') {
        activeTab.value = 'general';
    }
});

function onOpenChange(value: boolean): void {
    emit('update:open', value);
}

function onSuccess(): void {
    submitCount.value += 1;

    onOpenChange(false);
}

function onFieldTypeChange(value: FieldType): void {
    if (!isEdit.value || props.field === null) {
        selectedFieldType.value = value;

        return;
    }

    if (value === props.field.field_type) {
        return;
    }

    typeChangeError.value = null;
    pendingFieldType.value = value;
}

function cancelTypeChange(): void {
    pendingFieldType.value = null;
    typeChangeError.value = null;
    selectedFieldType.value = props.field?.field_type ?? 'text_short';
}

function confirmTypeChange(): void {
    if (props.field === null || pendingFieldType.value === null) {
        return;
    }

    const nextType = pendingFieldType.value;

    typeChangePending.value = true;

    router.put(
        FieldTypesController.update.url({
            objectType: props.objectTypeSlug,
            field: props.field.id,
        }),
        {
            field_type: nextType,
            confirm_lossy: typeChangeError.value !== null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                pendingFieldType.value = null;
                typeChangeError.value = null;
                selectedFieldType.value = nextType;
                onOpenChange(false);
            },
            onError: (errors) => {
                typeChangeError.value =
                    errors.field_type ??
                    t(
                        'i18n.components.engine.object_type.object_type_field_drawer.the_type_change_failed',
                    );
            },
            onFinish: () => {
                typeChangePending.value = false;
            },
        },
    );
}
</script>

<template>
    <Sheet :open="props.open" @update:open="onOpenChange">
        <SheetContent side="right" class="w-full gap-0 sm:max-w-2xl">
            <SheetHeader class="border-b">
                <SheetTitle>{{
                    isEdit
                        ? t(
                              'i18n.components.engine.object_type.object_type_field_drawer.edit_field',
                          )
                        : t(
                              'i18n.components.engine.object_type.object_type_field_drawer.new_field',
                          )
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        isEdit
                            ? t(
                                  'i18n.components.engine.object_type.object_type_field_drawer.change_a_field_definition_for_this_object_type',
                              )
                            : t(
                                  'i18n.components.engine.object_type.object_type_field_drawer.add_a_field_definition_to_this_object_type',
                              )
                    }}
                </SheetDescription>
            </SheetHeader>

            <Form
                :key="formKey"
                v-bind="formBinding"
                :options="{ preserveScroll: true }"
                :reset-on-success="true"
                class="flex min-h-0 flex-1 flex-col"
                @success="onSuccess"
                v-slot="{ errors, processing }"
            >
                <FieldTypePicker
                    v-if="isPickingType"
                    :field-types="props.fieldTypes"
                    @select="chooseFieldType"
                />

                <Tabs
                    extension-point="tabs.object-type-field-drawer"
                    :extension-context="$props"
                    v-else
                    v-model="activeTab"
                    class="flex min-h-0 flex-1 flex-col gap-0"
                >
                    <TabsList class="mx-4 mt-4 shrink-0 self-start">
                        <TabsTrigger
                            v-for="tab in tabs"
                            :key="tab.id"
                            :value="tab.id"
                            :data-field-tab="tab.id"
                            :data-has-error="
                                hasError(tab.id, errors) || undefined
                            "
                        >
                            {{ tab.label }}
                            <span
                                v-if="hasError(tab.id, errors)"
                                class="ml-1.5 size-1.5 rounded-full bg-destructive"
                                aria-hidden="true"
                            />
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent
                        value="general"
                        force-mount
                        data-field-tab-panel="general"
                        class="min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 data-[state=active]:flex data-[state=inactive]:hidden"
                    >
                        <div class="grid gap-2">
                            <Label for="field_label">{{
                                t(
                                    'i18n.components.engine.object_type.object_type_field_drawer.title',
                                )
                            }}</Label>
                            <Input
                                id="field_label"
                                name="label"
                                :placeholder="
                                    t(
                                        'i18n.components.engine.object_type.object_type_field_drawer.e_g_amount_the_field_s_label',
                                    )
                                "
                                :default-value="props.field?.label ?? ''"
                            />
                            <InputError :message="errors.label" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="field_description">{{
                                t(
                                    'i18n.components.engine.object_type.object_type_field_drawer.description',
                                )
                            }}</Label>
                            <Textarea
                                id="field_description"
                                name="description"
                                rows="2"
                                :placeholder="
                                    t(
                                        'i18n.components.engine.object_type.object_type_field_drawer.what_should_this_field_contain',
                                    )
                                "
                                :default-value="props.field?.description ?? ''"
                            />
                            <InputError :message="errors.description" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="field_key">{{
                                t(
                                    'i18n.components.engine.object_type.object_type_field_drawer.key',
                                )
                            }}</Label>
                            <Input
                                id="field_key"
                                name="key"
                                :placeholder="
                                    t(
                                        'i18n.components.engine.object_type.object_type_field_drawer.e_g_amount',
                                    )
                                "
                                required
                                :readonly="isEdit"
                                :class="isEdit ? 'bg-muted' : undefined"
                                :default-value="props.field?.key ?? ''"
                            />
                            <p
                                v-if="isEdit"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    isReserved
                                        ? reservedKeyHint
                                        : immutableKeyHint
                                }}
                            </p>
                            <InputError :message="errors.key" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="field_type">{{
                                t(
                                    'i18n.components.engine.object_type.object_type_field_drawer.type',
                                )
                            }}</Label>
                            <div
                                v-if="!isEdit"
                                class="flex items-center justify-between gap-3 rounded-md border border-input px-3 py-1.5"
                            >
                                <span data-field-type-chosen class="text-sm">
                                    {{ chosenTypeLabel }}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    data-field-type-change
                                    @click="hasChosenType = false"
                                >
                                    {{
                                        t(
                                            'i18n.components.engine.object_type.object_type_field_drawer.change_type',
                                        )
                                    }}
                                </Button>
                            </div>
                            <input
                                v-if="!isEdit"
                                type="hidden"
                                name="field_type"
                                :value="selectedFieldType"
                            />
                            <Select
                                v-if="isEdit"
                                :model-value="selectedFieldType"
                                :disabled="isTypeLocked"
                                @update:model-value="
                                    (value) =>
                                        onFieldTypeChange(value as FieldType)
                                "
                            >
                                <SelectTrigger id="field_type" class="w-full">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.engine.object_type.object_type_field_drawer.select_type',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in props.fieldTypes"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="isTypeLocked"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    isReserved
                                        ? reservedKeyHint
                                        : lockedTypeHint
                                }}
                            </p>
                            <InputError :message="errors.field_type" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="field_group_id">{{
                                t(
                                    'i18n.components.engine.object_type.object_type_field_drawer.field_group',
                                )
                            }}</Label>
                            <Select
                                v-model="selectedFieldGroup"
                                data-field-group-select
                            >
                                <SelectTrigger
                                    id="field_group_id"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.engine.object_type.object_type_field_drawer.select_group',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="UNGROUPED_VALUE">
                                        {{
                                            t(
                                                'i18n.components.engine.object_type.object_type_field_drawer.no_group',
                                            )
                                        }}
                                    </SelectItem>
                                    <SelectItem
                                        v-for="group in sortedGroups"
                                        :key="group.id"
                                        :value="group.id"
                                    >
                                        {{ group.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <input
                                type="hidden"
                                name="field_group_id"
                                :value="submittedFieldGroup"
                            />
                            <InputError :message="errors.field_group_id" />
                        </div>

                        <RelationFieldEditor
                            :key="`relation:${selectedFieldType}`"
                            :field-type="selectedFieldType"
                            :relationship-types="props.rollupTargets"
                            :initial-relationship-type-id="
                                initialRelationshipTypeId
                            "
                            :error-message="errors.config"
                        />

                        <FieldOptionsEditor
                            :key="`options:${selectedFieldType}`"
                            :field-type="selectedFieldType"
                            :initial-options="initialOptions"
                            :initial-lookup-object-type="
                                initialLookupObjectType
                            "
                            :object-type-options="props.objectTypeOptions"
                            :error-message="errors.config"
                        />
                    </TabsContent>

                    <TabsContent
                        v-if="isCalculated"
                        value="calculation"
                        force-mount
                        data-field-tab-panel="calculation"
                        class="min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 data-[state=active]:flex data-[state=inactive]:hidden"
                    >
                        <FormulaFieldEditor
                            :field-type="selectedFieldType"
                            :fields="props.fields"
                            :current-key="props.field?.key ?? null"
                            :initial-formula="
                                props.field?.config?.formula ?? null
                            "
                            :initial-result-type="
                                props.field?.config?.result_type ?? null
                            "
                            :error-message="errors.config"
                        />

                        <RollupFieldEditor
                            :field-type="selectedFieldType"
                            :rollup-targets="props.rollupTargets"
                            :initial-config="rollupInitialConfig"
                            :error-message="errors.config"
                        />
                        <FieldRecomputeAction
                            v-if="isEdit && props.field !== null"
                            :object-type-slug="props.objectTypeSlug"
                            :field-id="props.field.id"
                        />
                    </TabsContent>

                    <TabsContent
                        value="validation"
                        force-mount
                        data-field-tab-panel="validation"
                        class="min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 data-[state=active]:flex data-[state=inactive]:hidden"
                    >
                        <FieldDefaultValueEditor
                            :key="`default:${selectedFieldType}`"
                            :field-type="selectedFieldType"
                            :initial-default="
                                props.field?.default_value ?? null
                            "
                            :error-message="errors.default_value"
                        />

                        <FieldValidationRulesEditor
                            :key="`rules:${selectedFieldType}`"
                            :field-type="selectedFieldType"
                            :fields="props.fields"
                            :current-key="props.field?.key ?? null"
                            :initial-rules="
                                props.field?.validation_rules ?? null
                            "
                            :error-message="errors.validation_rules"
                        />
                    </TabsContent>

                    <TabsContent
                        value="behaviour"
                        force-mount
                        data-field-tab-panel="behaviour"
                        class="min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 data-[state=active]:flex data-[state=inactive]:hidden"
                    >
                        <FieldIndexingSection
                            :field="props.field"
                            :error-message="errors.is_encrypted"
                        />
                    </TabsContent>
                </Tabs>

                <SheetFooter v-if="!isPickingType" class="border-t">
                    <Button type="submit" :disabled="processing">
                        {{
                            isEdit
                                ? t(
                                      'i18n.components.engine.object_type.object_type_field_drawer.save',
                                  )
                                : t(
                                      'i18n.components.engine.object_type.object_type_field_drawer.add_field',
                                  )
                        }}
                    </Button>
                </SheetFooter>
            </Form>
        </SheetContent>

        <ConfirmDialog
            :open="pendingFieldType !== null"
            :title="
                t(
                    'i18n.components.engine.object_type.object_type_field_drawer.change_field_type',
                )
            "
            :description="typeChangeDescription"
            :confirm-label="typeChangeConfirmLabel"
            variant="destructive"
            :pending="typeChangePending"
            @cancel="cancelTypeChange"
            @confirm="confirmTypeChange"
        />
    </Sheet>
</template>
