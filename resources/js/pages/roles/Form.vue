<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RolesController from '@/actions/App/Http/Controllers/Authorization/RolesController';
import { buildFieldPermissionDelta } from '@/components/authorization/fieldPermissionDelta';
import FieldPermissionEditor from '@/components/authorization/FieldPermissionEditor.vue';
import PermissionGroupCard from '@/components/authorization/PermissionGroupCard.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { usePermissions } from '@/composables/usePermissions';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type {
    FieldGrantDraft,
    FieldPermissionGroup,
    FieldPermissionPayloadRow,
} from '@/types/fieldPermissions';
import type {
    RoleFormMode,
    RoleFormPermissions,
    RoleOption,
    RolePayload,
    RolePermissionGroup,
} from '@/types/roles';

const { t } = useI18n();

const props = defineProps<{
    mode: RoleFormMode;
    role: RolePayload | null;
    scopes: RoleOption[];
    authorities: RoleOption[];
    permissions: RoleFormPermissions;
    fieldPermissions: FieldPermissionGroup[];
    isEscalated: boolean;
}>();

const { isEscalated: isEscalatedActor } = usePermissions();

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.roles.form.new_role')
        : (props.role?.name ?? t('i18n.pages.roles.form.role')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const FIELD_KEYS = ['name', 'scope', 'authority', 'grants_subteam_visibility'];

const defaults = computed(() => ({
    name: props.role?.name ?? '',
    scope: props.role?.scope ?? props.scopes[0]?.value ?? '',
    authority: props.role?.authority ?? '',
}));

const NO_AUTHORITY = 'none';

const name = ref<string>(defaults.value.name);
const scope = ref<string>(defaults.value.scope);
const authority = ref<string>(
    defaults.value.authority === '' ? NO_AUTHORITY : defaults.value.authority,
);

const formAction = computed(() =>
    props.mode === 'create'
        ? RolesController.store.form()
        : RolesController.update.form({ role: props.role!.id }),
);

const selectedPermissionIds = ref<string[]>([...props.permissions.assigned]);

const isSystem = ref<boolean>(props.role?.is_system ?? false);

const showSystemFlag = computed<boolean>(
    () => props.mode === 'edit' && isEscalatedActor.value,
);

const grantsSubteamVisibility = ref<boolean>(
    props.role?.grants_subteam_visibility ?? false,
);

const FIELD_PERMISSION_TAB = 'field-permissions';

const activeTab = ref<string>(props.permissions.tabs[0]?.key ?? '');

function selectExtensionTab(key: string): void {
    activeTab.value = key;
}

const showFieldPermissions = computed<boolean>(
    () => props.mode === 'edit' && props.fieldPermissions.length > 0,
);

const tabs = computed<Array<{ key: string; label: string }>>(() => [
    ...props.permissions.tabs.map((tab) => ({
        key: tab.key,
        label: tab.label,
    })),
    ...(showFieldPermissions.value
        ? [
              {
                  key: FIELD_PERMISSION_TAB,
                  label: t('i18n.pages.roles.form.field_permissions'),
              },
          ]
        : []),
]);

function draftsFromServer(
    groups: FieldPermissionGroup[],
): Record<string, Record<string, FieldGrantDraft>> {
    return Object.fromEntries(
        groups.map((group) => [
            group.objectType.id,
            Object.fromEntries(
                group.fields.map((field) => [
                    field.id,
                    { read: field.read, write: field.write },
                ]),
            ),
        ]),
    );
}

const fieldDrafts = ref<Record<string, Record<string, FieldGrantDraft>>>(
    draftsFromServer(props.fieldPermissions),
);

watch(
    () => props.fieldPermissions,
    (groups) => {
        fieldDrafts.value = draftsFromServer(groups);
    },
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
        scope: scope.value,
        authority: authority.value,
        isSystem: isSystem.value,
        grantsSubteamVisibility: grantsSubteamVisibility.value,
        permissionIds: [...selectedPermissionIds.value].sort(),
        fieldDrafts: fieldDrafts.value,
    }),
    backHref: RolesController.index.url(),
});

const fieldPermissionPayload = computed<FieldPermissionPayloadRow[]>(() =>
    props.fieldPermissions.flatMap(
        (group) =>
            buildFieldPermissionDelta(
                group.fields,
                fieldDrafts.value[group.objectType.id] ?? {},
                { isEscalated: props.isEscalated },
            ).payload,
    ),
);

function updateGrant(value: {
    objectTypeId: string;
    fieldId: string;
    read: boolean;
    write: boolean;
}): void {
    fieldDrafts.value[value.objectTypeId] = {
        ...fieldDrafts.value[value.objectTypeId],
        [value.fieldId]: { read: value.read, write: value.write },
    };
}

const selectedGroupKey = ref<Record<string, string>>(
    Object.fromEntries(
        props.permissions.tabs
            .filter((tab) => tab.type === 'dropdown')
            .map((tab) => [tab.key, tab.groups[0]?.key ?? '']),
    ),
);

const currentGroups = computed<Record<string, RolePermissionGroup | undefined>>(
    () =>
        Object.fromEntries(
            props.permissions.tabs.map((tab) => {
                if (tab.type === 'dropdown') {
                    const key = selectedGroupKey.value[tab.key];

                    return [
                        tab.key,
                        tab.groups.find((group) => group.key === key) ??
                            tab.groups[0],
                    ];
                }

                return [tab.key, tab.groups[0]];
            }),
        ),
);

function togglePermission(id: string, granted: boolean): void {
    selectedPermissionIds.value = granted
        ? [...new Set([...selectedPermissionIds.value, id])]
        : selectedPermissionIds.value.filter((current) => current !== id);
}

function toggleGroupAll(tabKey: string, checked: boolean): void {
    const group = currentGroups.value[tabKey];

    if (group === undefined) {
        return;
    }

    const ids = group.permissions.map((permission) => permission.id);

    if (checked) {
        selectedPermissionIds.value = [
            ...new Set([...selectedPermissionIds.value, ...ids]),
        ];

        return;
    }

    const remove = new Set(ids);
    selectedPermissionIds.value = selectedPermissionIds.value.filter(
        (id) => !remove.has(id),
    );
}

function withPermissions(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    const payload: Record<string, FormDataConvertible> = {
        ...data,
        scope: scope.value,
        authority: authority.value === NO_AUTHORITY ? '' : authority.value,
        permission_ids: selectedPermissionIds.value,
        grants_subteam_visibility: grantsSubteamVisibility.value,
    };

    if (showSystemFlag.value) {
        payload.is_system = isSystem.value;
    }

    if (fieldPermissionPayload.value.length > 0) {
        payload.field_permissions = fieldPermissionPayload.value;
    }

    return payload;
}

function generalErrors(errors: Record<string, string>): string[] {
    return Object.entries(errors)
        .filter(([key]) => !FIELD_KEYS.includes(key))
        .map(([, message]) => message);
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head
            :title="
                mode === 'create'
                    ? t('i18n.pages.roles.form.new_role')
                    : t('i18n.pages.roles.form.role_2', {
                          value1: role?.name,
                      })
            "
        />

        <Heading
            variant="small"
            :title="heading"
            :description="
                mode === 'create'
                    ? t(
                          'i18n.pages.roles.form.create_a_role_with_a_scope_and_optional_authority',
                      )
                    : t(
                          'i18n.pages.roles.form.edit_this_role_s_name_scope_authority_and_permissions',
                      )
            "
        />

        <Form
            v-bind="formAction"
            :transform="withPermissions"
            :on-success="markSaved"
            class="flex flex-col gap-6"
            v-slot="{ errors, processing }"
        >
            <Alert
                v-if="generalErrors(errors).length > 0"
                variant="destructive"
            >
                <AlertDescription>
                    <p v-for="message in generalErrors(errors)" :key="message">
                        {{ message }}
                    </p>
                </AlertDescription>
            </Alert>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.roles.form.details')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.roles.form.the_scope_determines_the_level_at_which_the_role',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="role-name">{{
                                t('i18n.pages.roles.form.name')
                            }}</Label>
                            <Input
                                id="role-name"
                                v-model="name"
                                name="name"
                                :aria-invalid="Boolean(errors.name)"
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="role-scope">{{
                                t('i18n.pages.roles.form.scope')
                            }}</Label>
                            <Select v-model="scope">
                                <SelectTrigger
                                    id="role-scope"
                                    class="w-full"
                                    :aria-invalid="Boolean(errors.scope)"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.roles.form.select_scope',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in scopes"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.scope" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="role-authority">{{
                                t('i18n.pages.roles.form.authority')
                            }}</Label>
                            <Select
                                v-model="authority"
                                :disabled="!isEscalatedActor"
                            >
                                <SelectTrigger
                                    id="role-authority"
                                    class="w-full"
                                    :aria-invalid="Boolean(errors.authority)"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t('i18n.pages.roles.form.none')
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="NO_AUTHORITY">
                                        {{ t('i18n.pages.roles.form.none') }}
                                    </SelectItem>
                                    <SelectItem
                                        v-for="option in authorities"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="!isEscalatedActor"
                                class="text-sm text-muted-foreground"
                            >
                                {{
                                    t(
                                        'i18n.pages.roles.form.only_users_with_their_own_authority_may_change_a',
                                    )
                                }}
                            </p>
                            <InputError :message="errors.authority" />
                        </div>

                        <div class="grid gap-2">
                            <Label>{{
                                t('i18n.pages.roles.form.reach_into_subteams')
                            }}</Label>
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    id="role-grants-subteam-visibility"
                                    :model-value="grantsSubteamVisibility"
                                    :disabled="!isEscalatedActor"
                                    :title="
                                        isEscalatedActor
                                            ? undefined
                                            : t(
                                                  'i18n.pages.roles.form.only_users_with_authority_may_change_this_setting',
                                              )
                                    "
                                    @update:model-value="
                                        grantsSubteamVisibility =
                                            $event === true
                                    "
                                />
                                <Label
                                    for="role-grants-subteam-visibility"
                                    class="text-sm font-normal"
                                >
                                    {{
                                        t(
                                            'i18n.pages.roles.form.extends_down_the_team_tree',
                                        )
                                    }}
                                </Label>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'i18n.pages.roles.form.holders_of_this_role_also_manage_users_in_every',
                                    )
                                }}
                            </p>
                            <InputError
                                :message="errors.grants_subteam_visibility"
                            />
                        </div>

                        <div v-if="showSystemFlag" class="grid gap-2">
                            <Label>{{
                                t('i18n.pages.roles.form.system_role')
                            }}</Label>
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    id="role-is-system"
                                    :model-value="isSystem"
                                    @update:model-value="
                                        isSystem = $event === true
                                    "
                                />
                                <Label
                                    for="role-is-system"
                                    class="text-sm font-normal"
                                >
                                    {{
                                        t(
                                            'i18n.pages.roles.form.mark_as_protected_system_role',
                                        )
                                    }}
                                </Label>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'i18n.pages.roles.form.system_roles_are_locked_for_regular_managers_and_can',
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.roles.form.permissions')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.roles.form.determines_which_actions_holders_of_this_role_can_perform',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="tabs.length > 0"
                        class="flex flex-col gap-4 lg:flex-row lg:gap-6"
                    >
                        <aside class="lg:w-48 lg:shrink-0">
                            <nav
                                class="flex gap-1 overflow-x-auto lg:flex-col lg:space-y-1 lg:overflow-visible"
                                :aria-label="
                                    t('i18n.pages.roles.form.permissions_2')
                                "
                            >
                                <Button
                                    v-for="tab in tabs"
                                    :key="tab.key"
                                    type="button"
                                    variant="ghost"
                                    :class="[
                                        'shrink-0 justify-start lg:w-full',
                                        { 'bg-muted': activeTab === tab.key },
                                    ]"
                                    @click="activeTab = tab.key"
                                >
                                    {{ tab.label }}
                                </Button>
                                <UiExtensionPoint
                                    name="roles.permissions.navigation"
                                    :context="{
                                        ...$props,
                                        activeTab,
                                        selectTab: selectExtensionTab,
                                    }"
                                />
                            </nav>
                        </aside>

                        <div class="min-w-0 flex-1">
                            <UiExtensionPoint
                                name="roles.permissions.panels"
                                :context="{
                                    ...$props,
                                    activeTab,
                                    selectTab: selectExtensionTab,
                                }"
                            />
                            <template
                                v-for="tab in permissions.tabs"
                                :key="tab.key"
                            >
                                <div
                                    v-if="activeTab === tab.key"
                                    class="flex flex-col gap-3"
                                >
                                    <Select
                                        v-if="tab.type === 'dropdown'"
                                        :model-value="selectedGroupKey[tab.key]"
                                        @update:model-value="
                                            selectedGroupKey[tab.key] =
                                                ($event as string) ?? ''
                                        "
                                    >
                                        <SelectTrigger
                                            class="w-full sm:w-72"
                                            :aria-label="tab.label"
                                        >
                                            <SelectValue
                                                :placeholder="
                                                    t(
                                                        'i18n.pages.roles.form.select',
                                                        { value1: tab.label },
                                                    )
                                                "
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="group in tab.groups"
                                                :key="group.key"
                                                :value="group.key"
                                            >
                                                {{ group.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>

                                    <PermissionGroupCard
                                        :group="currentGroups[tab.key]"
                                        :selected-ids="selectedPermissionIds"
                                        @toggle="togglePermission"
                                        @toggle-all="
                                            (checked) =>
                                                toggleGroupAll(tab.key, checked)
                                        "
                                    />
                                </div>
                            </template>

                            <FieldPermissionEditor
                                v-if="
                                    showFieldPermissions &&
                                    activeTab === FIELD_PERMISSION_TAB
                                "
                                :groups="fieldPermissions"
                                :draft="fieldDrafts"
                                :is-escalated="isEscalated"
                                @update:grant="updateGrant"
                            />
                        </div>
                    </div>

                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            t(
                                'i18n.pages.roles.form.no_permissions_are_defined',
                            )
                        }}
                    </p>
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
