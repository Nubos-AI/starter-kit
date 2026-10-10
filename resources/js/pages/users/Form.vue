<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UsersController from '@/actions/App/Http/Controllers/Users/UsersController';
import EffectivePermissions from '@/components/authorization/EffectivePermissions.vue';
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
import type { MultiSelectOption } from '@/components/ui/multi-select';
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import UserPasswordCard from '@/components/users/UserPasswordCard.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type {
    AssignableRole,
    SalutationOption,
    UserFormPayload,
} from '@/types/users';

const { t } = useI18n();

const props = defineProps<{
    user: UserFormPayload;
    salutations: SalutationOption[];
    roles: AssignableRole[];
    teams: MultiSelectOption[];
    permissions: MultiSelectOption[];
}>();

const salutation = ref<string>(props.user.salutation ?? '');
const email = ref<string>(props.user.email);
const firstName = ref<string>(props.user.first_name ?? '');
const lastName = ref<string>(props.user.last_name ?? '');
const selectedRoleIds = ref<string[]>([...props.user.role_ids]);
const selectedTeamIds = ref<string[]>([...props.user.team_ids]);
const selectedDeniedPermissionIds = ref<string[]>([
    ...props.user.denied_permission_ids,
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
        salutation: salutation.value,
        email: email.value,
        firstName: firstName.value,
        lastName: lastName.value,
        roleIds: [...selectedRoleIds.value].sort(),
        teamIds: [...selectedTeamIds.value].sort(),
        deniedPermissionIds: [...selectedDeniedPermissionIds.value].sort(),
    }),
    backHref: UsersController.index.url(),
});

const roleOptions = computed<MultiSelectOption[]>(() =>
    props.roles.map((role) => ({
        value: role.id,
        label: role.name,
        badge:
            role.authority === null
                ? undefined
                : t('i18n.pages.users.form.elevated'),
        disabled: !role.can_assign,
        disabledReason: t(
            'i18n.pages.users.form.you_do_not_have_permission_to_assign_this_role',
        ),
    })),
);

const displayName = computed<string>(() => {
    const fullName = [props.user.first_name, props.user.last_name]
        .filter((part): part is string => Boolean(part))
        .join(' ');

    return fullName === '' ? props.user.email : fullName;
});

usePageBreadcrumbs(() => [{ title: displayName.value }]);

const deniedPermissionNames = computed<string[]>(() =>
    props.permissions
        .filter((permission) =>
            selectedDeniedPermissionIds.value.includes(permission.value),
        )
        .map((permission) => permission.label),
);

function withRoles(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        role_ids: selectedRoleIds.value,
        team_ids: selectedTeamIds.value,
        denied_permission_ids: selectedDeniedPermissionIds.value,
    };
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head
            :title="t('i18n.pages.users.form.user', { value1: displayName })"
        />

        <Heading
            variant="small"
            :title="displayName"
            :description="
                t(
                    'i18n.pages.users.form.this_user_s_basic_information_and_roles',
                )
            "
        />

        <Form
            v-bind="UsersController.update.form({ user: user.id })"
            :transform="withRoles"
            :on-success="markSaved"
            class="flex flex-col gap-6"
            v-slot="{ errors, processing }"
        >
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.users.form.basic_information')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.users.form.a_changed_email_address_must_be_verified_again',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="user-salutation">{{
                                t('i18n.pages.users.form.salutation')
                            }}</Label>
                            <Select v-model="salutation" name="salutation">
                                <SelectTrigger
                                    id="user-salutation"
                                    class="w-full"
                                    :aria-invalid="Boolean(errors.salutation)"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.users.form.select_salutation',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in salutations"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.salutation" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="user-email">{{
                                t('i18n.pages.users.form.email')
                            }}</Label>
                            <Input
                                id="user-email"
                                v-model="email"
                                name="email"
                                type="email"
                                :readonly="!user.can_update_password"
                                :aria-invalid="Boolean(errors.email)"
                                required
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="user-first-name">{{
                                t('i18n.pages.users.form.first_name')
                            }}</Label>
                            <Input
                                id="user-first-name"
                                v-model="firstName"
                                name="first_name"
                                :aria-invalid="Boolean(errors.first_name)"
                                required
                            />
                            <InputError :message="errors.first_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="user-last-name">{{
                                t('i18n.pages.users.form.last_name')
                            }}</Label>
                            <Input
                                id="user-last-name"
                                v-model="lastName"
                                name="last_name"
                                :aria-invalid="Boolean(errors.last_name)"
                                required
                            />
                            <InputError :message="errors.last_name" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.users.form.roles')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.users.form.multiple_roles_are_allowed_their_permissions_add_up_but',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="grid gap-2 lg:w-1/2">
                        <Label for="user-roles">{{
                            t('i18n.pages.users.form.assigned_roles')
                        }}</Label>
                        <MultiSelect
                            id="user-roles"
                            v-model="selectedRoleIds"
                            :disabled="!user.can_manage_access"
                            :options="roleOptions"
                            :placeholder="
                                t('i18n.pages.users.form.select_roles')
                            "
                            :empty-label="
                                t('i18n.pages.users.form.there_are_no_roles')
                            "
                            :aria-label="
                                t('i18n.pages.users.form.assigned_roles')
                            "
                        />
                        <InputError :message="errors.role_ids" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-medium">
                            {{
                                t('i18n.pages.users.form.effective_permissions')
                            }}
                        </p>
                        <EffectivePermissions
                            :roles="roles"
                            :selected-ids="selectedRoleIds"
                            :denied-names="deniedPermissionNames"
                        />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.users.form.explicitly_denied_permissions')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.users.form.a_denial_overrides_every_role_of_this_user_and',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="user-denied">{{
                            t('i18n.pages.users.form.denied_permissions')
                        }}</Label>
                        <MultiSelect
                            id="user-denied"
                            v-model="selectedDeniedPermissionIds"
                            :disabled="!user.can_manage_access"
                            :options="permissions"
                            :placeholder="
                                t('i18n.pages.users.form.select_permissions')
                            "
                            :empty-label="
                                t(
                                    'i18n.pages.users.form.there_are_no_permissions',
                                )
                            "
                            :aria-label="
                                t('i18n.pages.users.form.denied_permissions')
                            "
                        />
                        <InputError :message="errors.denied_permission_ids" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.users.form.teams')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.users.form.membership_determines_which_teams_this_user_can_select_in',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="user-teams">{{
                            t('i18n.pages.users.form.assigned_teams')
                        }}</Label>
                        <MultiSelect
                            id="user-teams"
                            v-model="selectedTeamIds"
                            :disabled="!user.can_manage_access"
                            :options="teams"
                            :placeholder="
                                t('i18n.pages.users.form.select_teams')
                            "
                            :empty-label="
                                t('i18n.pages.users.form.there_are_no_teams')
                            "
                            :aria-label="
                                t('i18n.pages.users.form.assigned_teams')
                            "
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

        <UserPasswordCard
            v-if="user.can_update_password"
            :user-id="user.id"
            :can-receive-reset-link="user.status === 'accepted'"
            :back-href="UsersController.index.url()"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
