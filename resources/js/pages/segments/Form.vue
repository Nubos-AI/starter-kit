<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import SegmentManagementController from '@/actions/App/Http/Controllers/Engine/SegmentManagementController';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import FormActions from '@/components/FormActions.vue';
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
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { FieldDefinition } from '@/types/fields';

const { t } = useI18n();

interface Option {
    value: string;
    label: string;
}

interface SegmentPayload {
    id: string;
    name: string;
    object_type_id: string | null;
    filter_definition: FilterGroupNode | null;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    segment: SegmentPayload | null;
    objectTypeOptions: Option[];
    fieldsByType: Record<string, FieldDefinition[]>;
}>();

const isEdit = computed<boolean>(() => props.mode === 'edit');

const heading = computed<string>(() =>
    isEdit.value
        ? (props.segment?.name ?? t('i18n.pages.segments.form.segment'))
        : t('i18n.pages.segments.form.new_segment'),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const form = useForm<{
    name: string;
    object_type_id: string;
    filter_definition: FilterGroupNode | null;
}>({
    name: props.segment?.name ?? '',
    object_type_id:
        props.segment?.object_type_id ??
        props.objectTypeOptions[0]?.value ??
        '',
    filter_definition: props.segment?.filter_definition ?? null,
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
        name: form.name,
        objectTypeId: form.object_type_id,
        filterDefinition: form.filter_definition,
    }),
    backHref: SegmentManagementController.index.url(),
});

const fields = computed<FieldDefinition[]>(
    () => props.fieldsByType[form.object_type_id] ?? [],
);

const canEditFilter = computed<boolean>(() => fields.value.length > 0);

const objectTypeLabel = computed<string>(
    () =>
        props.objectTypeOptions.find(
            (option) => option.value === form.object_type_id,
        )?.label ?? '',
);

const filterKey = computed<string>(() => form.object_type_id);

if (!isEdit.value) {
    watch(
        () => form.object_type_id,
        () => {
            form.filter_definition = null;
        },
    );
}

function onSubmit(): void {
    if (isEdit.value && props.segment !== null) {
        form.transform((data) => ({
            name: data.name,
            filter_definition: data.filter_definition,
        })).put(
            SegmentManagementController.update.url({
                segment: props.segment.id,
            }),
            { preserveScroll: true, onSuccess: markSaved },
        );

        return;
    }

    form.transform((data) => ({
        name: data.name,
        object_type_id: data.object_type_id === '' ? null : data.object_type_id,
        filter_definition: data.filter_definition,
    })).post(SegmentManagementController.store.url(), {
        preserveScroll: true,
        onSuccess: markSaved,
    });
}
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.segments.form.new_segment')
                : t('i18n.pages.segments.form.edit_segment', {
                      value1: segment?.name,
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
                          'i18n.pages.segments.form.create_a_new_segment_for_an_object_type',
                      )
                    : t(
                          'i18n.pages.segments.form.edit_this_segment_s_name_and_filters',
                      )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.segments.form.details')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.segments.form.segments_are_available_as_filters_in_the_corresponding_object',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form class="flex flex-col gap-4" @submit.prevent="onSubmit">
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="segment-name">{{
                            t('i18n.pages.segments.form.name')
                        }}</Label>
                        <Input
                            id="segment-name"
                            v-model="form.name"
                            :placeholder="
                                t('i18n.pages.segments.form.segment_name')
                            "
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="segment-object-type">{{
                            t('i18n.pages.segments.form.object_type')
                        }}</Label>
                        <Select v-if="!isEdit" v-model="form.object_type_id">
                            <SelectTrigger
                                id="segment-object-type"
                                class="w-full"
                            >
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.segments.form.select_object_type',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in objectTypeOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-else class="text-sm text-muted-foreground">
                            {{ objectTypeLabel || '—' }}
                        </p>
                        <InputError :message="form.errors.object_type_id" />
                    </div>

                    <div v-if="canEditFilter" class="flex flex-col gap-2">
                        <Label>{{
                            t('i18n.pages.segments.form.filter')
                        }}</Label>
                        <FilterBuilder
                            :key="filterKey"
                            :fields="fields"
                            :model-value="
                                segment?.filter_definition ?? undefined
                            "
                            :show-actions="false"
                            @update:model-value="
                                form.filter_definition = $event
                            "
                        />
                        <InputError :message="form.errors.filter_definition" />
                    </div>

                    <p
                        v-else
                        class="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
                    >
                        {{
                            t(
                                'i18n.pages.segments.form.no_filterable_fields_are_configured_for_this_object_type',
                            )
                        }}
                    </p>

                    <FormActions
                        :dirty="isDirty"
                        :processing="form.processing"
                        @cancel="requestLeave"
                    />
                </form>
            </CardContent>
        </Card>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
