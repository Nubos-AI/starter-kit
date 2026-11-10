<script setup lang="ts">
import { Pause, Pencil, Play } from '@lucide/vue';
import type { ColDef, ValueGetterParams } from 'ag-grid-community';
import { computed, ref } from 'vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ListPage from '@/components/data-grid/ListPage.vue';
import { statusBadgeColumn } from '@/components/data-grid/StatusBadgeCellRenderer';
import RuleEditor from '@/components/engine/rules/RuleEditor.vue';
import ViewStates from '@/components/engine/ViewStates.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { useRules } from '@/composables/useRules';
import type { NotificationRuleItem } from '@/composables/useRules';
import { NOTIFICATION_RULE_STATE } from '@/lib/statusMaps';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

interface NotificationRuleRow {
    id: string;
    name: string;
    trigger: string;
    state: 'active' | 'inactive';
    rule: NotificationRuleItem;
}

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        objectType: RecordObjectType;
        fields?: FieldDefinition[];
        rules?: NotificationRuleItem[];
    }>(),
    {
        fields: () => [],
        rules: () => [],
    },
);

const { canForObjectType } = usePermissions();

const { toggleActive } = useRules();

const canManageRules = computed<boolean>(() =>
    canForObjectType(props.objectType.slug, 'rules.manage'),
);

const deniedReason = t('i18n.pages.notification_rules.index.no_permission');

const items = ref<NotificationRuleItem[]>([...props.rules]);
const editorOpen = ref<boolean>(false);
const editingRule = ref<NotificationRuleItem | null>(null);

const triggerLabels: Record<string, string> = {
    date_based: t('i18n.pages.notification_rules.index.date'),
    stage_change: t('i18n.pages.notification_rules.index.stage_change'),
    assignment: t('i18n.pages.notification_rules.index.assignment'),
    field_change: t('i18n.pages.notification_rules.index.field_change'),
    creation: t('i18n.pages.notification_rules.index.creation'),
};

const rows = computed<NotificationRuleRow[]>(() =>
    items.value.map((rule) => ({
        id: rule.id,
        name: rule.name,
        trigger:
            triggerLabels[rule.trigger_type ?? ''] ?? rule.trigger_type ?? '',
        state: rule.is_active === true ? 'active' : 'inactive',
        rule,
    })),
);

const listState = computed<'empty' | 'ready'>(() =>
    rows.value.length === 0 ? 'empty' : 'ready',
);

function openCreate(): void {
    editingRule.value = null;
    editorOpen.value = true;
}

function openEdit(row: NotificationRuleRow): void {
    if (!canManageRules.value) {
        return;
    }

    editingRule.value = row.rule;
    editorOpen.value = true;
}

function onSaved(rule: NotificationRuleItem): void {
    const index = items.value.findIndex((entry) => entry.id === rule.id);

    if (index === -1) {
        items.value = [rule, ...items.value];
    } else {
        items.value = items.value.map((entry) =>
            entry.id === rule.id ? rule : entry,
        );
    }

    editorOpen.value = false;
}

async function setActive(
    row: NotificationRuleRow,
    isActive: boolean,
): Promise<void> {
    const result = await toggleActive(row.id, isActive);

    if (result === null) {
        return;
    }

    items.value = items.value.map((entry) =>
        entry.id === row.id ? { ...entry, is_active: isActive } : entry,
    );
}

const columnDefs = computed<ColDef<NotificationRuleRow>[]>(() => [
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.notification_rules.index.name'),
        flex: 2,
    },
    {
        colId: 'trigger',
        headerName: t('i18n.pages.notification_rules.index.trigger'),
        flex: 1,
        valueGetter: (params: ValueGetterParams<NotificationRuleRow>): string =>
            params.data?.trigger ?? '',
    },
    statusBadgeColumn<NotificationRuleRow>('state', NOTIFICATION_RULE_STATE, {
        headerName: t('i18n.pages.notification_rules.index.status'),
        width: 140,
    }),
    actionsColumn<NotificationRuleRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.notification_rules.index.edit'),
            variant: 'edit',
            testId: 'rule-edit',
            isDisabled: () => !canManageRules.value,
            disabledReason: () => deniedReason,
            onClick: (row) => openEdit(row),
        },
        {
            icon: Play,
            label: t('i18n.pages.notification_rules.index.enable'),
            variant: 'success',
            testId: 'rule-enable',
            isVisible: (row) => row.state === 'inactive',
            isDisabled: () => !canManageRules.value,
            disabledReason: () => deniedReason,
            onClick: (row) => void setActive(row, true),
        },
        {
            icon: Pause,
            label: t('i18n.pages.notification_rules.index.disable'),
            variant: 'warning',
            testId: 'rule-disable',
            isVisible: (row) => row.state === 'active',
            isDisabled: () => !canManageRules.value,
            disabledReason: () => deniedReason,
            onClick: (row) => void setActive(row, false),
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="t('i18n.pages.notification_rules.index.notification_rules')"
        :description="
            t(
                'i18n.pages.notification_rules.index.rules_for_triggers_scope_and_actions',
                { value1: props.objectType.name },
            )
        "
    >
        <template #actions>
            <CreateButton
                v-if="canManageRules"
                :label="t('i18n.pages.notification_rules.index.new_rule')"
                @click="openCreate"
            />
        </template>

        <div class="relative flex min-h-40 flex-1 flex-col" data-rule-list>
            <ViewStates
                :state="listState"
                empty-kind="no-records"
                skeleton-variant="list"
                :empty-title="
                    t(
                        'i18n.pages.notification_rules.index.no_rules_available_yet',
                    )
                "
                :empty-description="
                    t(
                        'i18n.pages.notification_rules.index.active_rules_notify_owners_and_watchers_including_the_person',
                    )
                "
                :create-label="
                    t('i18n.pages.notification_rules.index.new_rule')
                "
                :can-create="canManageRules"
                @create="openCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="rows"
                    :is-row-activatable="() => canManageRules"
                    :aria-label="
                        t(
                            'i18n.pages.notification_rules.index.notification_rules',
                        )
                    "
                    @row-activate="openEdit"
                />
            </div>
        </div>

        <Dialog v-model:open="editorOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editingRule
                                ? t(
                                      'i18n.pages.notification_rules.index.edit_rule',
                                  )
                                : t(
                                      'i18n.pages.notification_rules.index.new_rule',
                                  )
                        }}
                    </DialogTitle>
                </DialogHeader>
                <RuleEditor
                    :object-type="objectType"
                    :fields="fields"
                    :rule="editingRule"
                    @saved="onSaved"
                    @cancel="editorOpen = false"
                />
            </DialogContent>
        </Dialog>
    </ListPage>
</template>
