<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RelationshipTypesController from '@/actions/App/Http/Controllers/Engine/RelationshipTypesController';
import FormActions from '@/components/FormActions.vue';
import FormCheckbox from '@/components/FormCheckbox.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

const { t } = useI18n();

interface Option {
    value: string;
    label: string;
}

interface RelationshipTypePayload {
    id: string;
    key: string;
    name: string;
    inverse_name: string;
    from_object_type_id: string;
    to_object_type_id: string;
    cardinality: string;
    cascade_behavior: string;
    is_required: boolean;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    relationshipType: RelationshipTypePayload | null;
    objectTypeOptions: Option[];
    cardinalities: Option[];
    cascadeBehaviors: Option[];
}>();

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.relationship_types.form.new_relationship_type')
        : (props.relationshipType?.name ??
          t('i18n.pages.relationship_types.form.relationship_type')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const defaults = computed(() => ({
    from_object_type_id:
        props.relationshipType?.from_object_type_id ??
        props.objectTypeOptions[0]?.value ??
        '',
    to_object_type_id:
        props.relationshipType?.to_object_type_id ??
        props.objectTypeOptions[0]?.value ??
        '',
    name: props.relationshipType?.name ?? '',
    inverse_name: props.relationshipType?.inverse_name ?? '',
    cardinality:
        props.relationshipType?.cardinality ??
        props.cardinalities[0]?.value ??
        '',
    cascade_behavior:
        props.relationshipType?.cascade_behavior ??
        props.cascadeBehaviors[0]?.value ??
        '',
    is_required: props.relationshipType?.is_required ?? false,
}));

const name = ref<string>(defaults.value.name);
const inverseName = ref<string>(defaults.value.inverse_name);
const fromObjectTypeId = ref<string>(defaults.value.from_object_type_id);
const toObjectTypeId = ref<string>(defaults.value.to_object_type_id);
const cardinality = ref<string>(defaults.value.cardinality);
const cascadeBehavior = ref<string>(defaults.value.cascade_behavior);
const isRequired = ref<boolean>(defaults.value.is_required);

const formAction = computed(() =>
    props.mode === 'create'
        ? RelationshipTypesController.store.form()
        : RelationshipTypesController.update.form({
              relationshipType: props.relationshipType!.id,
          }),
);

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
        inverseName: inverseName.value,
        fromObjectTypeId: fromObjectTypeId.value,
        toObjectTypeId: toObjectTypeId.value,
        cardinality: cardinality.value,
        cascadeBehavior: cascadeBehavior.value,
        isRequired: isRequired.value,
    }),
    backHref: RelationshipTypesController.index.url(),
});
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.relationship_types.form.new_relationship_type')
                : t('i18n.pages.relationship_types.form.edit', {
                      value1: relationshipType?.name,
                  })
        "
    />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="heading"
            :description="
                mode === 'create'
                    ? t(
                          'i18n.pages.relationship_types.form.define_how_records_of_one_object_type_relate_to',
                      )
                    : t(
                          'i18n.pages.relationship_types.form.edit_this_relationship_type',
                      )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.relationship_types.form.details')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.relationship_types.form.automations_use_this_in_the_link_relationship_action',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="formAction"
                    :on-success="markSaved"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="from-type">{{
                                t(
                                    'i18n.pages.relationship_types.form.source_object_type',
                                )
                            }}</Label>
                            <Select
                                v-model="fromObjectTypeId"
                                name="from_object_type_id"
                            >
                                <SelectTrigger id="from-type" class="w-full">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.relationship_types.form.select_source_object_type',
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
                            <InputError :message="errors.from_object_type_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="to-type">{{
                                t(
                                    'i18n.pages.relationship_types.form.target_object_type',
                                )
                            }}</Label>
                            <Select
                                v-model="toObjectTypeId"
                                name="to_object_type_id"
                            >
                                <SelectTrigger id="to-type" class="w-full">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.relationship_types.form.select_target_object_type',
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
                            <InputError :message="errors.to_object_type_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rel-name">{{
                                t(
                                    'i18n.pages.relationship_types.form.label_source_target',
                                )
                            }}</Label>
                            <Input
                                id="rel-name"
                                v-model="name"
                                name="name"
                                :placeholder="
                                    t(
                                        'i18n.pages.relationship_types.form.e_g_orders',
                                    )
                                "
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rel-inverse">
                                {{
                                    t(
                                        'i18n.pages.relationship_types.form.reverse_label_target_source',
                                    )
                                }}
                            </Label>
                            <Input
                                id="rel-inverse"
                                v-model="inverseName"
                                name="inverse_name"
                                :placeholder="
                                    t(
                                        'i18n.pages.relationship_types.form.e_g_company',
                                    )
                                "
                                required
                            />
                            <InputError :message="errors.inverse_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rel-cardinality">{{
                                t(
                                    'i18n.pages.relationship_types.form.cardinality',
                                )
                            }}</Label>
                            <Select v-model="cardinality" name="cardinality">
                                <SelectTrigger
                                    id="rel-cardinality"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.relationship_types.form.select_cardinality',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in props.cardinalities"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.cardinality" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rel-cascade">{{
                                t(
                                    'i18n.pages.relationship_types.form.deletion_behaviour',
                                )
                            }}</Label>
                            <Select
                                v-model="cascadeBehavior"
                                name="cascade_behavior"
                            >
                                <SelectTrigger id="rel-cascade" class="w-full">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.relationship_types.form.select_deletion_behaviour',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in props.cascadeBehaviors"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.cascade_behavior" />
                        </div>
                    </div>

                    <FormCheckbox
                        v-model="isRequired"
                        name="is_required"
                        :label="
                            t(
                                'i18n.pages.relationship_types.form.required_relationship',
                            )
                        "
                    />

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </Form>
            </CardContent>
        </Card>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
