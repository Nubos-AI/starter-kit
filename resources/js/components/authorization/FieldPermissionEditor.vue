<script setup lang="ts">
import { ShieldAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { FIELD_RESTRICTION_STATE } from '@/lib/statusMaps';
import type { StatusEntry } from '@/lib/statusMaps';
import type {
    FieldGrantDraft,
    FieldPermissionEntry,
    FieldPermissionGroup,
} from '@/types/fieldPermissions';

const { t } = useI18n();

const props = defineProps<{
    groups: FieldPermissionGroup[];
    draft: Record<string, Record<string, FieldGrantDraft>>;
    isEscalated: boolean;
}>();

const emit = defineEmits<{
    'update:grant': [
        value: {
            objectTypeId: string;
            fieldId: string;
            read: boolean;
            write: boolean;
        },
    ];
}>();

const selectedObjectTypeId = ref<string>(props.groups[0]?.objectType.id ?? '');

const currentGroup = computed<FieldPermissionGroup | undefined>(
    () =>
        props.groups.find(
            (group) => group.objectType.id === selectedObjectTypeId.value,
        ) ?? props.groups[0],
);

const fields = computed<FieldPermissionEntry[]>(
    () => currentGroup.value?.fields ?? [],
);

const restrictedCount = computed<number>(
    () => fields.value.filter((field) => isRestricted(field)).length,
);

const counterLabel = computed<string>(() =>
    t(
        'i18n.components.authorization.field_permission_editor.of_fields_restricted',
        { value1: restrictedCount.value, value2: fields.value.length },
    ),
);

function valueOf(field: FieldPermissionEntry): FieldGrantDraft {
    return (
        props.draft[currentGroup.value?.objectType.id ?? '']?.[field.id] ?? {
            read: field.read,
            write: field.write,
        }
    );
}

function isRestricted(field: FieldPermissionEntry): boolean {
    const grant = valueOf(field);

    return !grant.read || !grant.write;
}

function stateOf(field: FieldPermissionEntry): StatusEntry {
    return isRestricted(field)
        ? FIELD_RESTRICTION_STATE.restricted
        : FIELD_RESTRICTION_STATE.open;
}

function onGrant(field: FieldPermissionEntry, grant: FieldGrantDraft): void {
    emit('update:grant', {
        objectTypeId: currentGroup.value?.objectType.id ?? '',
        fieldId: field.id,
        read: grant.read,
        write: grant.read && grant.write,
    });
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <Select
                :model-value="selectedObjectTypeId"
                @update:model-value="
                    selectedObjectTypeId = ($event as string) ?? ''
                "
            >
                <SelectTrigger
                    class="w-full sm:w-72"
                    :aria-label="
                        t(
                            'i18n.components.authorization.field_permission_editor.object_type',
                        )
                    "
                >
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.authorization.field_permission_editor.select_object_type',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="group in props.groups"
                        :key="group.objectType.id"
                        :value="group.objectType.id"
                    >
                        {{ group.objectType.name }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <span class="text-xs text-muted-foreground">
                {{ counterLabel }}
            </span>
        </div>

        <Alert v-if="props.isEscalated">
            <ShieldAlert />
            <AlertTitle>{{
                t(
                    'i18n.components.authorization.field_permission_editor.field_permissions_do_not_apply_here',
                )
            }}</AlertTitle>
            <AlertDescription>
                {{
                    t(
                        'i18n.components.authorization.field_permission_editor.this_role_has_higher_authority_and_bypasses_all_field',
                    )
                }}
            </AlertDescription>
        </Alert>

        <p v-if="fields.length === 0" class="text-sm text-muted-foreground">
            {{
                t(
                    'i18n.components.authorization.field_permission_editor.no_fields_are_defined_for_this_object_type',
                )
            }}
        </p>

        <template v-else>
            <p v-if="!props.isEscalated" class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.components.authorization.field_permission_editor.restrictions_apply_to_this_role_only',
                    )
                }}
            </p>

            <ul class="flex flex-col gap-1">
                <li
                    v-for="field in fields"
                    :key="field.id"
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 rounded-md px-2 py-1.5 sm:grid-cols-[minmax(0,1fr)_auto_6rem_7rem]"
                >
                    <span class="truncate font-mono text-xs">
                        {{ field.key }}
                    </span>

                    <Badge
                        :data-testid="`field-state-${field.id}`"
                        :variant="stateOf(field).variant"
                    >
                        {{ stateOf(field).label }}
                    </Badge>

                    <div class="flex items-center gap-1.5">
                        <Checkbox
                            :id="`field-read-${field.id}`"
                            :model-value="valueOf(field).read"
                            :disabled="props.isEscalated"
                            @update:model-value="
                                onGrant(field, {
                                    read: $event === true,
                                    write: valueOf(field).write,
                                })
                            "
                        />
                        <Label
                            :for="`field-read-${field.id}`"
                            class="text-xs font-normal"
                        >
                            {{
                                t(
                                    'i18n.components.authorization.field_permission_editor.see',
                                )
                            }}
                        </Label>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <Checkbox
                            :id="`field-write-${field.id}`"
                            :model-value="valueOf(field).write"
                            :disabled="
                                props.isEscalated || !valueOf(field).read
                            "
                            @update:model-value="
                                onGrant(field, {
                                    read: valueOf(field).read,
                                    write: $event === true,
                                })
                            "
                        />
                        <Label
                            :for="`field-write-${field.id}`"
                            class="text-xs font-normal"
                        >
                            {{
                                t(
                                    'i18n.components.authorization.field_permission_editor.edit',
                                )
                            }}
                        </Label>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
