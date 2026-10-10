<script setup lang="ts">
import { ShieldAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useI18n } from '@/composables/useI18n';
import {
    permissionActionLabel,
    permissionGroupLabel,
} from '@/lib/permissionLabels';
import type { AssignableRole } from '@/types/users';

const { t } = useI18n();

interface EffectiveAbility {
    label: string;
    denied: boolean;
}

interface EffectiveGroup {
    key: string;
    label: string;
    abilities: EffectiveAbility[];
}

const props = withDefaults(
    defineProps<{
        roles: AssignableRole[];
        selectedIds: string[];
        deniedNames?: string[];
    }>(),
    { deniedNames: () => [] },
);

const selectedRoles = computed<AssignableRole[]>(() =>
    props.roles.filter((role) => props.selectedIds.includes(role.id)),
);

const escalatedRoles = computed<AssignableRole[]>(() =>
    selectedRoles.value.filter((role) => role.authority !== null),
);

const groups = computed<EffectiveGroup[]>(() => {
    const denied = new Set(props.deniedNames);
    const collected = new Map<string, Map<string, boolean>>();

    for (const role of selectedRoles.value) {
        for (const permission of role.permissions ?? []) {
            const groupKey = permission.group ?? permission.name.split('.')[0];
            const abilities =
                collected.get(groupKey) ?? new Map<string, boolean>();
            const label = permissionActionLabel(permission.name, groupKey);

            abilities.set(
                label,
                (abilities.get(label) ?? true) && denied.has(permission.name),
            );
            collected.set(groupKey, abilities);
        }
    }

    return [...collected.entries()]
        .map(([key, abilities]) => ({
            key,
            label: permissionGroupLabel(key),
            abilities: [...abilities.entries()]
                .map(([label, isDenied]) => ({ label, denied: isDenied }))
                .sort((a, b) => a.label.localeCompare(b.label)),
        }))
        .sort((a, b) => a.label.localeCompare(b.label));
});
</script>

<template>
    <div class="flex flex-col gap-3">
        <Alert v-if="escalatedRoles.length > 0">
            <ShieldAlert class="size-4" />
            <AlertTitle>{{
                t(
                    'i18n.components.authorization.effective_permissions.elevated_role_selected',
                )
            }}</AlertTitle>
            <AlertDescription>
                <p>
                    {{ escalatedRoles.map((role) => role.name).join(', ') }}
                    {{
                        t(
                            'i18n.components.authorization.effective_permissions.overrides_individual_permissions_an_elevated_role_allows_everything_within',
                        )
                    }}
                </p>
            </AlertDescription>
        </Alert>

        <p
            v-if="selectedRoles.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'i18n.components.authorization.effective_permissions.this_user_has_no_permissions_without_a_role',
                )
            }}
        </p>

        <p
            v-else-if="groups.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'i18n.components.authorization.effective_permissions.the_selected_roles_have_no_individual_permissions',
                )
            }}
        </p>

        <dl v-if="groups.length > 0" class="flex flex-col gap-1">
            <div
                v-for="group in groups"
                :key="group.key"
                class="flex flex-col gap-0.5 text-sm sm:flex-row sm:gap-3"
                data-effective-group
            >
                <dt class="text-muted-foreground sm:w-44 sm:shrink-0">
                    {{ group.label }}
                </dt>
                <dd class="flex flex-wrap gap-x-1">
                    <span
                        v-for="(ability, index) in group.abilities"
                        :key="ability.label"
                        :class="
                            ability.denied
                                ? 'text-muted-foreground line-through'
                                : undefined
                        "
                        :title="
                            ability.denied
                                ? t(
                                      'i18n.components.authorization.effective_permissions.explicitly_denied_the_role_grants_access_but_the_denial',
                                  )
                                : undefined
                        "
                        data-effective-ability
                    >
                        {{ ability.label
                        }}{{ index < group.abilities.length - 1 ? ',' : '' }}
                    </span>
                </dd>
            </div>
        </dl>
    </div>
</template>
