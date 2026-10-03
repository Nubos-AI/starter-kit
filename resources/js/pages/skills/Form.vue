<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SkillsController from '@/actions/App/Http/Controllers/Skills/SkillsController';
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
import { MultiSelect } from '@/components/ui/multi-select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

interface SkillPayload {
    id: string;
    name: string;
    userIds: string[];
}

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        skill: SkillPayload | null;
        userOptions?: SelectOption[];
    }>(),
    { userOptions: () => [] },
);

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.skills.form.create_skill')
        : (props.skill?.name ?? t('i18n.pages.skills.form.skill')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const formAction = computed(() =>
    props.mode === 'create'
        ? SkillsController.store.form()
        : SkillsController.update.form({ skill: props.skill?.id ?? '' }),
);

const name = ref<string>(props.skill?.name ?? '');
const userIds = ref<string[]>([...(props.skill?.userIds ?? [])]);

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
        userIds: [...userIds.value].sort(),
    }),
    backHref: SkillsController.index.url(),
});

function withSelections(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    if (props.mode === 'create') {
        return { ...data };
    }

    return { ...data, user_ids: userIds.value };
}
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.skills.form.create_skill')
                : t('i18n.pages.skills.form.edit', { value1: skill?.name })
        "
    />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="heading"
            :description="
                mode === 'create'
                    ? t(
                          'i18n.pages.skills.form.create_a_skill_that_you_can_then_assign_to',
                      )
                    : t(
                          'i18n.pages.skills.form.rename_this_skill_or_change_who_has_it',
                      )
            "
        />

        <Form
            v-bind="formAction"
            :transform="withSelections"
            :on-success="markSaved"
            class="flex flex-col gap-6"
            v-slot="{ errors, processing }"
        >
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.skills.form.details')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.skills.form.the_name_appears_wherever_a_skill_is_selected',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="skill-name">{{
                            t('i18n.pages.skills.form.name')
                        }}</Label>
                        <Input
                            id="skill-name"
                            v-model="name"
                            name="name"
                            :placeholder="
                                t(
                                    'i18n.pages.skills.form.e_g_accounting_legal_purchasing',
                                )
                            "
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>
                </CardContent>
            </Card>

            <Card v-if="mode === 'edit'">
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.skills.form.assigned_users')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.skills.form.users_listed_here_have_this_skill_and_are_eligible',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="skill-users">{{
                            t('i18n.pages.skills.form.users')
                        }}</Label>
                        <MultiSelect
                            id="skill-users"
                            v-model="userIds"
                            :options="props.userOptions"
                            :placeholder="
                                t('i18n.pages.skills.form.select_users')
                            "
                            :empty-label="
                                t('i18n.pages.skills.form.there_are_no_users')
                            "
                            :aria-label="
                                t('i18n.pages.skills.form.assigned_users')
                            "
                        />
                        <InputError :message="errors.user_ids" />
                    </div>
                </CardContent>
            </Card>

            <FormActions
                :dirty="isDirty"
                :processing="processing"
                @cancel="requestLeave"
            />
        </Form>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
