<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TeamAccessRulesController from '@/actions/App/Http/Controllers/Teams/TeamAccessRulesController';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { MultiSelect } from '@/components/ui/multi-select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

interface TeamPayload {
    id: string;
    name: string;
    slug: string;
    ownerId: string | null;
    memberIds: string[];
    roleIds: string[];
    deniedPermissionIds: string[];
}

const props = defineProps<{
    mode: 'create' | 'edit';
    team: TeamPayload | null;
    parentOptions: SelectOption[];
    ownerOptions: SelectOption[];
    memberOptions: SelectOption[];
    roleOptions: SelectOption[];
    permissionOptions: SelectOption[];
}>();

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.teams.form.create_team')
        : (props.team?.name ?? t('i18n.pages.teams.form.team')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const formAction = computed(() =>
    props.mode === 'create'
        ? TeamsController.store.form()
        : TeamsController.update.form({ team: props.team?.id ?? '' }),
);

const NO_SELECTION = 'none';

const name = ref<string>(props.team?.name ?? '');
const slug = ref<string>(props.team?.slug ?? '');
const parentTeamId = ref<string>(NO_SELECTION);
const ownerId = ref<string>(props.team?.ownerId ?? NO_SELECTION);
const memberIds = ref<string[]>([...(props.team?.memberIds ?? [])]);
const roleIds = ref<string[]>([...(props.team?.roleIds ?? [])]);
const deniedPermissionIds = ref<string[]>([
    ...(props.team?.deniedPermissionIds ?? []),
]);

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
        memberIds: [...memberIds.value].sort(),
        roleIds: [...roleIds.value].sort(),
        deniedPermissionIds: [...deniedPermissionIds.value].sort(),
    }),
    backHref: TeamsController.index.url(),
});

function withSelections(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    const payload: Record<string, FormDataConvertible> = {
        ...data,
        parent_team_id:
            parentTeamId.value === NO_SELECTION ? null : parentTeamId.value,
        owner_id: ownerId.value === NO_SELECTION ? null : ownerId.value,
    };

    if (props.mode === 'edit') {
        payload.member_ids = memberIds.value;
        payload.role_ids = roleIds.value;
        payload.denied_permission_ids = deniedPermissionIds.value;
    }

    return payload;
}
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.teams.form.create_team')
                : t('i18n.pages.teams.form.edit', { value1: team?.name })
        "
    />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="heading"
            :description="
                mode === 'create'
                    ? t(
                          'i18n.pages.teams.form.teams_form_the_tree_that_determines_record_visibility',
                      )
                    : t(
                          'i18n.pages.teams.form.rename_this_team_or_transfer_it_to_another_owner',
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
                <CardContent>
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

                        <div v-if="mode === 'create'" class="grid gap-2">
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
                </CardContent>
            </Card>

            <Card v-if="mode === 'edit'">
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.teams.form.members')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.teams.form.team_members_can_select_it_in_the_team_switcher',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="team-members">{{
                            t('i18n.pages.teams.form.assigned_members')
                        }}</Label>
                        <MultiSelect
                            id="team-members"
                            v-model="memberIds"
                            :options="props.memberOptions"
                            :placeholder="
                                t('i18n.pages.teams.form.select_members')
                            "
                            :empty-label="
                                t('i18n.pages.teams.form.there_are_no_users')
                            "
                            :aria-label="
                                t('i18n.pages.teams.form.assigned_members')
                            "
                        />
                        <InputError :message="errors.member_ids" />
                    </div>
                </CardContent>
            </Card>

            <Card v-if="mode === 'edit'">
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.teams.form.roles')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.teams.form.these_roles_apply_to_each_member_while_working_in',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="team-roles">{{
                            t('i18n.pages.teams.form.assigned_roles')
                        }}</Label>
                        <MultiSelect
                            id="team-roles"
                            v-model="roleIds"
                            :options="props.roleOptions"
                            :placeholder="
                                t('i18n.pages.teams.form.select_roles')
                            "
                            :empty-label="
                                t(
                                    'i18n.pages.teams.form.there_are_no_assignable_roles',
                                )
                            "
                            :aria-label="
                                t('i18n.pages.teams.form.assigned_roles')
                            "
                        />
                        <InputError :message="errors.role_ids" />
                    </div>
                </CardContent>
            </Card>

            <Card v-if="mode === 'edit'">
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.teams.form.explicitly_denied_permissions')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.teams.form.a_denial_here_overrides_this_team_s_roles_a',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="team-denied">{{
                            t('i18n.pages.teams.form.denied_permissions')
                        }}</Label>
                        <MultiSelect
                            id="team-denied"
                            v-model="deniedPermissionIds"
                            :options="props.permissionOptions"
                            :placeholder="
                                t('i18n.pages.teams.form.select_permissions')
                            "
                            :empty-label="
                                t(
                                    'i18n.pages.teams.form.there_are_no_permissions',
                                )
                            "
                            :aria-label="
                                t('i18n.pages.teams.form.denied_permissions')
                            "
                        />
                        <InputError :message="errors.denied_permission_ids" />
                    </div>
                </CardContent>
            </Card>

            <Card v-if="mode === 'edit'">
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.teams.form.record_access')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.teams.form.for_each_object_type_choose_which_records_this_team',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button as-child variant="outline" type="button">
                        <Link
                            :href="
                                TeamAccessRulesController.index.url({
                                    team: props.team?.id ?? '',
                                })
                            "
                            data-team-access-rules-link
                        >
                            {{ t('i18n.pages.teams.form.edit_access_rules') }}
                        </Link>
                    </Button>
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
