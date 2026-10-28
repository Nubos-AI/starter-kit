<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
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
import { Textarea } from '@/components/ui/textarea';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { DashboardRow } from '@/types/dashboards';

const { t } = useI18n();

const props = defineProps<{
    mode: 'create' | 'edit';
    dashboard: DashboardRow | null;
}>();

const isEdit = computed<boolean>(() => props.mode === 'edit');

const heading = computed<string>(() =>
    isEdit.value
        ? (props.dashboard?.name ?? t('i18n.pages.dashboards.form.dashboard'))
        : t('i18n.pages.dashboards.form.new_dashboard'),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const name = ref<string>(props.dashboard?.name ?? '');
const description = ref<string>(props.dashboard?.description ?? '');

const form = useForm<Record<string, string>>({});

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({ name: name.value, description: description.value }),
    backHref: DashboardsController.index.url(),
});

function submitPayload(): Record<string, string | null> {
    return {
        name: name.value,
        description: description.value.trim() === '' ? null : description.value,
    };
}

function onSaved(): void {
    markSaved();
    toast.success(t('i18n.pages.dashboards.form.the_dashboard_was_saved'));
}

function onSubmit(): void {
    const dashboard = props.dashboard;

    if (isEdit.value && dashboard !== null) {
        form.transform(() => submitPayload()).put(
            DashboardsController.update.url({ dashboard: dashboard.id }),
            { preserveScroll: true, onSuccess: onSaved },
        );

        return;
    }

    form.transform(() => submitPayload()).post(
        DashboardsController.store.url(),
        { preserveScroll: true, onSuccess: onSaved },
    );
}
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.dashboards.form.new_dashboard')
                : t('i18n.pages.dashboards.form.edit_dashboard', {
                      value1: dashboard?.name,
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
                          'i18n.pages.dashboards.form.create_a_new_dashboard_and_give_it_a_name',
                      )
                    : t(
                          'i18n.pages.dashboards.form.edit_this_dashboard_s_name_and_description',
                      )
            "
        />

        <form class="flex flex-col gap-6" @submit.prevent="onSubmit">
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.dashboards.form.basic_information')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.dashboards.form.the_name_and_description_appear_in_the_list_and',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="dashboard-name">{{
                            t('i18n.pages.dashboards.form.name')
                        }}</Label>
                        <Input
                            id="dashboard-name"
                            v-model="name"
                            :placeholder="
                                t('i18n.pages.dashboards.form.dashboard_name')
                            "
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="dashboard-description">{{
                            t('i18n.pages.dashboards.form.description')
                        }}</Label>
                        <Textarea
                            id="dashboard-description"
                            v-model="description"
                            :placeholder="
                                t(
                                    'i18n.pages.dashboards.form.what_is_this_dashboard_for',
                                )
                            "
                            rows="2"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </CardContent>
            </Card>

            <FormActions
                :dirty="isDirty"
                :processing="form.processing"
                @cancel="requestLeave"
            />
        </form>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
