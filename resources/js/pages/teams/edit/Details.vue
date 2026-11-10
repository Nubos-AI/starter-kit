<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import FormActions from '@/components/FormActions.vue';
import InputError from '@/components/InputError.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useTeamSection } from '@/composables/useTeamSection';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

interface TeamDetails {
    id: string;
    name: string;
    slug: string;
    ownerId: string | null;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    team: TeamDetails | null;
    parentOptions: SelectOption[];
    ownerOptions: SelectOption[];
}>();

useTeamSection(props.team, 'details');

const NO_SELECTION = 'none';

const formAction = computed(() =>
    props.team === null
        ? TeamsController.store.form()
        : TeamsController.update.form({ team: props.team.id }),
);

const name = ref<string>(props.team?.name ?? '');
const slug = ref<string>(props.team?.slug ?? '');
const parentTeamId = ref<string>(NO_SELECTION);
const ownerId = ref<string>(props.team?.ownerId ?? NO_SELECTION);

const parentChoices = computed<SelectOption[]>(() => [
    {
        value: NO_SELECTION,
        label: t('i18n.pages.teams.form.no_parent_team_root'),
    },
    ...props.parentOptions,
]);

const ownerChoices = computed<SelectOption[]>(() => [
    { value: NO_SELECTION, label: t('i18n.pages.teams.form.no_owner') },
    ...props.ownerOptions,
]);

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
        slug: slug.value,
        parentTeamId: parentTeamId.value,
        ownerId: ownerId.value,
    }),
    backHref: TeamsController.index.url(),
});

function selectionOrNull(value: string): string | null {
    return value === NO_SELECTION ? null : value;
}

function detailsOnly(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    const payload: Record<string, FormDataConvertible> = {
        name: data.name,
        slug: data.slug,
        owner_id: selectionOrNull(ownerId.value),
    };

    if (props.mode === 'create') {
        payload.parent_team_id = selectionOrNull(parentTeamId.value);
    }

    return payload;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head
            :title="
                props.team === null
                    ? t('i18n.pages.teams.form.create_team')
                    : t('i18n.pages.teams.form.edit', {
                          value1: props.team.name,
                      })
            "
        />

        <Form
            v-bind="formAction"
            :transform="detailsOnly"
            :options="{ preserveScroll: true }"
            :on-success="markSaved"
            v-slot="{ errors, processing }"
        >
            <Card data-team-details>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.teams.form.details')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.teams.form.the_code_is_derived_from_the_name_if_left',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="team-name">{{
                                t('i18n.pages.teams.form.name')
                            }}</Label>
                            <Input
                                id="team-name"
                                v-model="name"
                                name="name"
                                :placeholder="
                                    t('i18n.pages.teams.form.e_g_sales_north')
                                "
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="team-slug">{{
                                t('i18n.pages.teams.form.code')
                            }}</Label>
                            <Input
                                id="team-slug"
                                v-model="slug"
                                name="slug"
                                :placeholder="
                                    t('i18n.pages.teams.form.e_g_sales_north_2')
                                "
                            />
                            <InputError :message="errors.slug" />
                        </div>

                        <div v-if="props.mode === 'create'" class="grid gap-2">
                            <Label for="team-parent">{{
                                t('i18n.pages.teams.form.parent_team')
                            }}</Label>
                            <Combobox
                                id="team-parent"
                                v-model="parentTeamId"
                                :options="parentChoices"
                                :aria-label="
                                    t('i18n.pages.teams.form.parent_team')
                                "
                            />
                            <InputError :message="errors.parent_team_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="team-owner">{{
                                t('i18n.pages.teams.form.owner')
                            }}</Label>
                            <Combobox
                                id="team-owner"
                                v-model="ownerId"
                                :options="ownerChoices"
                                :search-placeholder="
                                    t('i18n.pages.teams.form.search_people')
                                "
                                :aria-label="t('i18n.pages.teams.form.owner')"
                            />
                            <InputError :message="errors.owner_id" />
                        </div>
                    </div>

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </CardContent>
            </Card>
        </Form>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
