<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ActivityTypesController from '@/actions/App/Http/Controllers/Engine/ActivityTypesController';
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
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

const { t } = useI18n();

interface ActivityTypePayload {
    id: string;
    name: string;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    activityType: ActivityTypePayload | null;
}>();

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.activity_types.form.create_activity_type')
        : (props.activityType?.name ??
          t('i18n.pages.activity_types.form.activity_type')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const formAction = computed(() =>
    props.mode === 'create'
        ? ActivityTypesController.store.form()
        : ActivityTypesController.update.form({
              activityType: props.activityType!.id,
          }),
);

const name = ref<string>(props.activityType?.name ?? '');

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({ name: name.value }),
    backHref: ActivityTypesController.index.url(),
});
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.activity_types.form.create_activity_type')
                : t('i18n.pages.activity_types.form.edit', {
                      value1: activityType?.name,
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
                          'i18n.pages.activity_types.form.create_a_category_to_select_when_adding_an_activity',
                      )
                    : t(
                          'i18n.pages.activity_types.form.edit_this_activity_type',
                      )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.activity_types.form.details')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.activity_types.form.record_activities_use_these_categories',
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
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="activity-type-name">{{
                            t('i18n.pages.activity_types.form.name')
                        }}</Label>
                        <Input
                            id="activity-type-name"
                            v-model="name"
                            name="name"
                            :placeholder="
                                t(
                                    'i18n.pages.activity_types.form.e_g_phone_call_meeting_customer_visit',
                                )
                            "
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

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
