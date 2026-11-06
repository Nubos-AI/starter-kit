<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RolesController from '@/actions/App/Http/Controllers/Authorization/RolesController';
import SimpleTable from '@/components/data-grid/SimpleTable.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { useI18n } from '@/composables/useI18n';
import { useObjectTypeSection } from '@/composables/useObjectTypeSection';
import { FIELD_RESTRICTION_STATE } from '@/lib/statusMaps';
import type { StatusEntry } from '@/lib/statusMaps';
import type {
    FieldGrantDraft,
    FieldPermissionMatrix,
    FieldPermissionMatrixField,
    FieldPermissionMatrixRole,
} from '@/types/fieldPermissions';
import type { ObjectTypeSummary } from '@/types/objectTypes';

const { t } = useI18n();

const props = defineProps<{
    objectType: ObjectTypeSummary;
    matrix: FieldPermissionMatrix;
}>();

useObjectTypeSection(props.objectType, 'permissions');

const restrictedCount = computed<number>(
    () => props.matrix.fields.filter((field) => field.restricted).length,
);

const counterLabel = computed<string>(() =>
    t('i18n.pages.object_types.edit.permissions.of_fields_restricted', {
        value1: restrictedCount.value,
        value2: props.matrix.fields.length,
    }),
);

function grantOf(
    role: FieldPermissionMatrixRole,
    field: FieldPermissionMatrixField,
): FieldGrantDraft {
    return (
        props.matrix.grants[role.id]?.[field.id] ?? {
            read: true,
            write: true,
        }
    );
}

function stateOf(field: FieldPermissionMatrixField): StatusEntry {
    return field.restricted
        ? FIELD_RESTRICTION_STATE.restricted
        : FIELD_RESTRICTION_STATE.open;
}
</script>

<template>
    <Head
        :title="
            t('i18n.pages.object_types.edit.permissions.permissions_2', {
                value1: objectType.name,
            })
        "
    />

    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.pages.object_types.edit.permissions.permissions')
            }}</CardTitle>
            <CardDescription>
                {{
                    t(
                        'i18n.pages.object_types.edit.permissions.field_permissions_for_this_object_type_across_all_roles',
                    )
                }}
            </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-3">
            <p
                v-if="matrix.fields.length === 0"
                class="text-sm text-muted-foreground"
                data-testid="permission-matrix-empty"
            >
                {{
                    t(
                        'i18n.pages.object_types.edit.permissions.no_fields_are_defined_for_this_object_type',
                    )
                }}
            </p>

            <template v-else>
                <span
                    class="text-xs text-muted-foreground"
                    data-testid="permission-matrix-counter"
                >
                    {{ counterLabel }}
                </span>

                <div class="overflow-x-auto">
                    <SimpleTable data-testid="permission-matrix">
                        <thead>
                            <tr>
                                <th class="text-left">
                                    {{
                                        t(
                                            'i18n.pages.object_types.edit.permissions.field',
                                        )
                                    }}
                                </th>
                                <th class="text-left">
                                    {{
                                        t(
                                            'i18n.pages.object_types.edit.permissions.state',
                                        )
                                    }}
                                </th>
                                <th
                                    v-for="role in matrix.roles"
                                    :key="role.id"
                                    colspan="2"
                                    class="border-l text-center"
                                >
                                    <Link
                                        :href="
                                            RolesController.edit({
                                                role: role.id,
                                            })
                                        "
                                        class="underline underline-offset-4"
                                    >
                                        {{ role.name }}
                                    </Link>
                                    <Badge
                                        v-if="role.bypassesFieldPermissions"
                                        variant="secondary"
                                        class="mt-1 block w-fit"
                                    >
                                        {{
                                            t(
                                                'i18n.pages.object_types.edit.permissions.bypasses_field_permissions',
                                            )
                                        }}
                                    </Badge>
                                </th>
                            </tr>
                            <tr>
                                <th></th>
                                <th></th>
                                <template
                                    v-for="role in matrix.roles"
                                    :key="`sub-${role.id}`"
                                >
                                    <th
                                        class="border-l text-center text-xs text-muted-foreground"
                                    >
                                        {{
                                            t(
                                                'i18n.pages.object_types.edit.permissions.see',
                                            )
                                        }}
                                    </th>
                                    <th
                                        class="text-center text-xs text-muted-foreground"
                                    >
                                        {{
                                            t(
                                                'i18n.pages.object_types.edit.permissions.edit',
                                            )
                                        }}
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="field in matrix.fields"
                                :key="field.id"
                                class="border-t"
                                :data-testid="`permission-row-${field.key}`"
                            >
                                <td class="font-mono text-xs">
                                    {{ field.key }}
                                </td>
                                <td>
                                    <Badge :variant="stateOf(field).variant">
                                        {{ stateOf(field).label }}
                                    </Badge>
                                </td>
                                <template
                                    v-for="role in matrix.roles"
                                    :key="`${field.id}-${role.id}`"
                                >
                                    <td class="border-l text-center">
                                        <Checkbox
                                            class="mx-auto"
                                            :model-value="
                                                grantOf(role, field).read
                                            "
                                            disabled
                                            :aria-label="
                                                t(
                                                    'i18n.pages.object_types.edit.permissions.sees',
                                                    {
                                                        value1: role.name,
                                                        value2: field.key,
                                                    },
                                                )
                                            "
                                        />
                                    </td>
                                    <td class="text-center">
                                        <Checkbox
                                            class="mx-auto"
                                            :model-value="
                                                grantOf(role, field).write
                                            "
                                            disabled
                                            :aria-label="
                                                t(
                                                    'i18n.pages.object_types.edit.permissions.edits',
                                                    {
                                                        value1: role.name,
                                                        value2: field.key,
                                                    },
                                                )
                                            "
                                        />
                                    </td>
                                </template>
                            </tr>
                        </tbody>
                    </SimpleTable>
                </div>
            </template>
        </CardContent>
    </Card>
</template>
