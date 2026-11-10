<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { permissionActionLabel } from '@/lib/permissionLabels';
import type { RolePermissionGroup } from '@/types/roles';

const { t } = useI18n();

const props = defineProps<{
    group?: RolePermissionGroup;
    selectedIds: string[];
}>();

const emit = defineEmits<{
    toggle: [id: string, granted: boolean];
    toggleAll: [checked: boolean];
}>();

const selectedCount = computed<number>(
    () =>
        props.group?.permissions.filter((permission) =>
            props.selectedIds.includes(permission.id),
        ).length ?? 0,
);

const total = computed<number>(() => props.group?.permissions.length ?? 0);

const masterState = computed<boolean | 'indeterminate'>(() => {
    if (selectedCount.value === 0) {
        return false;
    }

    if (selectedCount.value === total.value) {
        return true;
    }

    return 'indeterminate';
});
</script>

<template>
    <p v-if="!group || total === 0" class="text-sm text-muted-foreground">
        {{
            t(
                'i18n.components.authorization.permission_group_card.no_permissions_are_defined',
            )
        }}
    </p>

    <div v-else class="flex flex-col gap-3 rounded-lg border border-border p-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Checkbox
                    :id="`perm-all-${group.key}`"
                    :model-value="masterState"
                    @update:model-value="emit('toggleAll', $event === true)"
                />
                <Label
                    :for="`perm-all-${group.key}`"
                    class="text-sm font-medium"
                >
                    {{
                        t(
                            'i18n.components.authorization.permission_group_card.all',
                        )
                    }}
                </Label>
            </div>
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ selectedCount }}/{{ total }}
            </span>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
            <div
                v-for="permission in group.permissions"
                :key="permission.id"
                class="flex items-center gap-2"
            >
                <Checkbox
                    :id="`permission-${permission.id}`"
                    :model-value="selectedIds.includes(permission.id)"
                    @update:model-value="
                        emit('toggle', permission.id, $event === true)
                    "
                />
                <Label
                    :for="`permission-${permission.id}`"
                    class="text-sm font-normal"
                >
                    {{ permissionActionLabel(permission.name, group.key) }}
                </Label>
            </div>
        </div>
    </div>
</template>
