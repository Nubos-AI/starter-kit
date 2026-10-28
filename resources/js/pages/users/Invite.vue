<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserInvitationsController from '@/actions/App/Http/Controllers/Users/UserInvitationsController';
import UsersController from '@/actions/App/Http/Controllers/Users/UsersController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import type { MultiSelectOption } from '@/components/ui/multi-select';
import { MultiSelect } from '@/components/ui/multi-select';
import { Textarea } from '@/components/ui/textarea';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { AssignableRole } from '@/types/users';

const { t } = useI18n();

const props = defineProps<{
    management?: { storeUrl: string; indexUrl: string };
    roles: AssignableRole[];
    teams: MultiSelectOption[];
}>();

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.users.invite.invite_users') },
]);

const emails = ref<string>('');
const selectedRoleIds = ref<string[]>([]);
const selectedTeamIds = ref<string[]>([]);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        emails: emails.value,
        roleIds: [...selectedRoleIds.value].sort(),
        teamIds: [...selectedTeamIds.value].sort(),
    }),
    backHref: props.management?.indexUrl ?? UsersController.index.url(),
});

const roleOptions = computed<MultiSelectOption[]>(() =>
    props.roles.map((role) => ({
        value: role.id,
        label: role.name,
        badge:
            role.authority === null
                ? undefined
                : t('i18n.pages.users.invite.elevated'),
        disabled: !role.can_assign,
        disabledReason: t(
            'i18n.pages.users.invite.you_may_not_assign_this_role',
        ),
    })),
);

function withSelections(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        role_ids: selectedRoleIds.value,
        team_ids: selectedTeamIds.value,
    };
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="t('i18n.pages.users.invite.invite_users')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.users.invite.invite_users')"
            :description="
                t(
                    'i18n.pages.users.invite.invitees_appear_in_the_list_immediately_and_complete_their',
                )
            "
        />

        <Form
            v-bind="
                management
                    ? { action: management.storeUrl, method: 'post' }
                    : UserInvitationsController.store.form()
            "
            :transform="withSelections"
            :on-success="markSaved"
            class="flex flex-col gap-6"
            v-slot="{ errors, processing }"
        >
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.users.invite.invitation')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.users.invite.separate_multiple_addresses_with_commas_each_address_receives_its',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2">
                        <Label for="invite-emails">{{
                            t('i18n.pages.users.invite.email_addresses')
                        }}</Label>
                        <Textarea
                            id="invite-emails"
                            v-model="emails"
                            name="emails"
                            rows="4"
                            :placeholder="
                                t(
                                    'i18n.pages.users.invite.anna_example_com_ben_example_com',
                                )
                            "
                            :aria-invalid="Boolean(errors.emails)"
                        />
                        <InputError :message="errors.emails" />
                    </div>
                </CardContent>
            </Card>

            <UiExtensionPoint name="users.invite.fields" />
            <Card v-if="!management">
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.users.invite.roles_and_teams')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.users.invite.applies_to_all_addresses_in_this_invitation_both_can',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="invite-roles">{{
                            t('i18n.pages.users.invite.roles')
                        }}</Label>
                        <MultiSelect
                            id="invite-roles"
                            v-model="selectedRoleIds"
                            :options="roleOptions"
                            :placeholder="
                                t('i18n.pages.users.invite.select_roles')
                            "
                            :empty-label="
                                t('i18n.pages.users.invite.there_are_no_roles')
                            "
                            :aria-label="t('i18n.pages.users.invite.roles')"
                        />
                        <InputError :message="errors.role_ids" />
                    </div>

                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="invite-teams">{{
                            t('i18n.pages.users.invite.teams')
                        }}</Label>
                        <MultiSelect
                            id="invite-teams"
                            v-model="selectedTeamIds"
                            :options="teams"
                            :placeholder="
                                t('i18n.pages.users.invite.select_teams')
                            "
                            :empty-label="
                                t('i18n.pages.users.invite.there_are_no_teams')
                            "
                            :aria-label="t('i18n.pages.users.invite.teams')"
                        />
                        <InputError :message="errors.team_ids" />
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
