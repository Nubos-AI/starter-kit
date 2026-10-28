<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import FormActions from '@/components/FormActions.vue';
import FormCheckbox from '@/components/FormCheckbox.vue';
import InputError from '@/components/InputError.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useObjectTypeSection } from '@/composables/useObjectTypeSection';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { iconFor } from '@/lib/navIcons';
import type { NavIconOption, ObjectTypeSummary } from '@/types/objectTypes';

const { t } = useI18n();

const props = defineProps<{
    objectType: ObjectTypeSummary | null;
    navIcons: NavIconOption[];
}>();

const HIERARCHY_HINT = t(
    'i18n.pages.object_types.edit.details.parent_child_relationships_are_created_automatically_when_enabled_and',
);

const DELETION_REASON_HINT = t(
    'i18n.pages.object_types.edit.details.if_required_every_deletion_must_include_a_reason_it',
);

const NAVIGATION_HINT = t(
    'i18n.pages.object_types.edit.details.unchecking_this_only_hides_the_menu_entry_object_type',
);

const NAV_POSITION_HINT = t(
    'i18n.pages.object_types.edit.details.lower_numbers_appear_first_equal_numbers_are_sorted_alphabetically',
);

const RETENTION_HINT = t(
    'i18n.pages.object_types.edit.details.number_of_days_a_deleted_record_remains_in_the',
);

const CREATE_HEADING = t(
    'i18n.pages.object_types.edit.details.new_object_type',
);

const DEFAULT_NUMBER_FORMAT = '##########';

const isCreating = computed<boolean>(() => props.objectType === null);

useObjectTypeSection(props.objectType, 'details');

const name = ref<string>(props.objectType?.name ?? '');
const key = ref<string>('');
const businessKeyPrefix = ref<string>(
    props.objectType?.business_key_prefix ?? '',
);
const recordNumberFormat = ref<string>(
    props.objectType === null
        ? DEFAULT_NUMBER_FORMAT
        : (props.objectType.record_number_format ?? ''),
);
const hierarchyEnabled = ref<boolean>(
    props.objectType?.hierarchy_relationship_type_id != null,
);
const requiresDeletionReason = ref<boolean>(
    props.objectType?.requires_deletion_reason === true,
);
const isNavigable = ref<boolean>(props.objectType?.is_navigable !== false);
const navPosition = ref<string>(String(props.objectType?.nav_position ?? 0));
const navIcon = ref<string>(props.objectType?.nav_icon ?? 'database');
const retentionDays = ref<string>(
    props.objectType?.retention_days == null
        ? ''
        : String(props.objectType.retention_days),
);

const moduleValues = reactive<Record<string, unknown>>({});

function setModuleValue(key: string, value: unknown): void {
    moduleValues[key] = value;
}

const businessKeyLocked = computed<boolean>(
    () => props.objectType?.business_key_locked === true,
);

const isSystem = computed<boolean>(() => props.objectType?.is_system === true);

const formAction = computed(() =>
    props.objectType === null
        ? ObjectTypesController.store.form()
        : ObjectTypesController.update.form({
              objectType: props.objectType.slug,
          }),
);

const businessKeyPreview = computed<string>(() => {
    const prefix = businessKeyPrefix.value.trim();
    const format = recordNumberFormat.value.trim();

    if (prefix === '' || !format.includes('#')) {
        return '';
    }

    return `${prefix}-${format.replaceAll('#', '0')}`;
});

const businessKeyHint = computed<string>(() => {
    const base = t(
        'i18n.pages.object_types.edit.details.the_code_and_numbering_pattern_form_the_business_key',
    );
    const locked = businessKeyLocked.value
        ? t(
              'i18n.pages.object_types.edit.details.both_are_locked_once_the_object_type_has_records',
          )
        : '';
    const preview =
        businessKeyPreview.value === ''
            ? ''
            : t(
                  'i18n.pages.object_types.edit.details.the_first_record_will_receive',
                  { value1: businessKeyPreview.value },
              );

    return base + locked + preview;
});

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        name: name.value,
        key: key.value,
        businessKeyPrefix: businessKeyPrefix.value,
        recordNumberFormat: recordNumberFormat.value,
        hierarchyEnabled: hierarchyEnabled.value,
        requiresDeletionReason: requiresDeletionReason.value,
        isNavigable: isNavigable.value,
        navPosition: navPosition.value,
        navIcon: navIcon.value,
        retentionDays: retentionDays.value,
        ...moduleValues,
    }),
    backHref: ObjectTypesController.index.url(),
});
</script>

<template>
    <Head
        :title="
            objectType === null
                ? CREATE_HEADING
                : t('i18n.pages.object_types.edit.details.edit', {
                      value1: objectType.name,
                  })
        "
    />

    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.pages.object_types.edit.details.details')
            }}</CardTitle>
            <CardDescription>
                {{
                    t(
                        'i18n.pages.object_types.edit.details.the_slug_is_derived_from_the_name_and_cannot',
                    )
                }}
            </CardDescription>
        </CardHeader>
        <CardContent>
            <Form
                v-if="!isSystem"
                v-bind="formAction"
                :on-success="markSaved"
                class="flex flex-col gap-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">{{
                        t('i18n.pages.object_types.edit.details.name')
                    }}</Label>
                    <Input id="name" v-model="name" name="name" required />
                    <InputError :message="errors.name" />
                </div>
                <div v-if="isCreating" class="grid gap-2">
                    <Label for="key">{{
                        t('i18n.pages.object_types.edit.details.key')
                    }}</Label>
                    <Input id="key" v-model="key" name="key" required />
                    <InputError :message="errors.key" />
                </div>
                <div class="grid gap-2">
                    <div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                        <div class="grid gap-2">
                            <Label for="business_key_prefix">{{
                                t('i18n.pages.object_types.edit.details.code')
                            }}</Label>
                            <Input
                                id="business_key_prefix"
                                v-model="businessKeyPrefix"
                                name="business_key_prefix"
                                maxlength="4"
                                :readonly="businessKeyLocked"
                                :class="
                                    businessKeyLocked ? 'bg-muted' : undefined
                                "
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="record_number_format">
                                {{
                                    t(
                                        'i18n.pages.object_types.edit.details.numbering_pattern',
                                    )
                                }}
                            </Label>
                            <Input
                                id="record_number_format"
                                v-model="recordNumberFormat"
                                name="record_number_format"
                                maxlength="10"
                                :readonly="businessKeyLocked"
                                :class="
                                    businessKeyLocked ? 'bg-muted' : undefined
                                "
                                required
                            />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ businessKeyHint }}
                    </p>
                    <InputError :message="errors.business_key_prefix" />
                    <InputError :message="errors.record_number_format" />
                </div>
                <div v-if="!isCreating" class="grid gap-2">
                    <Label for="requires_deletion_reason">{{
                        t('i18n.pages.object_types.edit.details.deletion')
                    }}</Label>
                    <FormCheckbox
                        v-model="requiresDeletionReason"
                        name="requires_deletion_reason"
                        :label="
                            t(
                                'i18n.pages.object_types.edit.details.require_a_reason_for_deletion',
                            )
                        "
                        :description="DELETION_REASON_HINT"
                    />
                    <InputError :message="errors.requires_deletion_reason" />
                </div>
                <div v-if="!isCreating" class="grid gap-2 sm:max-w-sm">
                    <Label for="retention_days">{{
                        t(
                            'i18n.pages.object_types.edit.details.retention_period',
                        )
                    }}</Label>
                    <Input
                        id="retention_days"
                        v-model="retentionDays"
                        name="retention_days"
                        type="number"
                        min="1"
                        inputmode="numeric"
                        :placeholder="
                            t('i18n.pages.object_types.edit.details.e_g_14')
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ RETENTION_HINT }}
                    </p>
                    <InputError :message="errors.retention_days" />
                </div>
                <div v-if="!isCreating" class="grid gap-2">
                    <Label for="is_navigable">{{
                        t('i18n.pages.object_types.edit.details.navigation')
                    }}</Label>
                    <FormCheckbox
                        v-model="isNavigable"
                        name="is_navigable"
                        :label="
                            t(
                                'i18n.pages.object_types.edit.details.show_in_menu',
                            )
                        "
                        :description="NAVIGATION_HINT"
                    />
                    <InputError :message="errors.is_navigable" />
                </div>
                <div
                    v-if="!isCreating"
                    class="grid gap-4 sm:grid-cols-[8rem_1fr]"
                >
                    <div class="grid gap-2">
                        <Label for="nav_position">{{
                            t('i18n.pages.object_types.edit.details.position')
                        }}</Label>
                        <Input
                            id="nav_position"
                            v-model="navPosition"
                            name="nav_position"
                            type="number"
                            min="0"
                            inputmode="numeric"
                        />
                        <InputError :message="errors.nav_position" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nav_icon">{{
                            t('i18n.pages.object_types.edit.details.icon')
                        }}</Label>
                        <Select v-model="navIcon" name="nav_icon">
                            <SelectTrigger id="nav_icon" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.object_types.edit.details.select_icon',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in props.navIcons"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    <span class="flex items-center gap-2">
                                        <component
                                            :is="iconFor(option.value)"
                                            class="size-4"
                                        />
                                        {{ option.label }}
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.nav_icon" />
                    </div>
                </div>
                <p v-if="!isCreating" class="text-xs text-muted-foreground">
                    {{ NAV_POSITION_HINT }}
                </p>
                <div v-if="!isCreating" class="grid gap-2">
                    <Label for="hierarchy_enabled">{{
                        t('i18n.pages.object_types.edit.details.hierarchy')
                    }}</Label>
                    <FormCheckbox
                        v-model="hierarchyEnabled"
                        name="hierarchy_enabled"
                        :label="
                            t(
                                'i18n.pages.object_types.edit.details.enable_hierarchy',
                            )
                        "
                        :description="HIERARCHY_HINT"
                    />
                    <InputError :message="errors.hierarchy_enabled" />
                </div>
                <UiExtensionPoint
                    name="object-types.details.fields"
                    :context="{ objectType, setModuleValue, errors }"
                />
                <FormActions
                    :dirty="isDirty"
                    :processing="processing"
                    @cancel="requestLeave"
                />
            </Form>

            <dl v-else-if="objectType !== null" class="grid gap-2 text-sm">
                <div class="flex gap-2">
                    <dt class="text-muted-foreground">
                        {{ t('i18n.pages.object_types.edit.details.slug') }}
                    </dt>
                    <dd>{{ objectType.slug }}</dd>
                </div>
            </dl>
        </CardContent>
    </Card>

    <UnsavedChangesDialog
        :open="promptOpen"
        @confirm="confirmLeave"
        @cancel="cancelLeave"
    />
</template>
