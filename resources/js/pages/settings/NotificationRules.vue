<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import CreateButton from '@/components/CreateButton.vue';
import RuleEditor from '@/components/engine/rules/RuleEditor.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

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

const items = ref<NotificationRuleItem[]>([...props.rules]);
const editorOpen = ref<boolean>(false);
const editingRule = ref<NotificationRuleItem | null>(null);

const triggerLabels: Record<string, string> = {
    date_based: t('i18n.pages.settings.notification_rules.date'),
    stage_change: t('i18n.pages.settings.notification_rules.stage_change'),
    assignment: t('i18n.pages.settings.notification_rules.assignment'),
    field_change: t('i18n.pages.settings.notification_rules.field_change'),
    creation: t('i18n.pages.settings.notification_rules.creation'),
};

function openCreate(): void {
    editingRule.value = null;
    editorOpen.value = true;
}

function openEdit(rule: NotificationRuleItem): void {
    editingRule.value = rule;
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

async function onToggle(rule: NotificationRuleItem): Promise<void> {
    const nextActive = rule.is_active !== true;
    const result = await toggleActive(rule.id, nextActive);

    if (result !== null) {
        items.value = items.value.map((entry) =>
            entry.id === rule.id ? { ...entry, is_active: nextActive } : entry,
        );
    }
}
</script>

<template>
    <Head
        :title="t('i18n.pages.settings.notification_rules.notification_rules')"
    />

    <h1 class="sr-only">
        {{ t('i18n.pages.settings.notification_rules.notification_rules') }}
    </h1>

    <div class="flex flex-col gap-8">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="
                    t(
                        'i18n.pages.settings.notification_rules.notification_rules',
                    )
                "
                :description="
                    t(
                        'i18n.pages.settings.notification_rules.rules_for_triggers_scope_and_actions',
                        { value1: props.objectType.name },
                    )
                "
            />
            <CreateButton
                v-if="canForObjectType(props.objectType.slug, 'rules.manage')"
                :label="t('i18n.pages.settings.notification_rules.new_rule')"
                @click="openCreate"
            />
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.notification_rules.rules')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.notification_rules.active_rules_notify_owners_and_watchers_including_the_person',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p
                    v-if="items.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'i18n.pages.settings.notification_rules.no_rules_available_yet',
                        )
                    }}
                </p>

                <div
                    v-for="rule in items"
                    :key="rule.id"
                    class="flex items-center justify-between gap-4 rounded-md border border-input p-3"
                >
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ rule.name }}</span>
                        <div class="flex items-center gap-2">
                            <Badge variant="outline">
                                {{
                                    triggerLabels[rule.trigger_type ?? ''] ??
                                    rule.trigger_type
                                }}
                            </Badge>
                            <Badge
                                :variant="
                                    rule.is_active === true
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{
                                    rule.is_active === true
                                        ? t(
                                              'i18n.pages.settings.notification_rules.active',
                                          )
                                        : t(
                                              'i18n.pages.settings.notification_rules.inactive',
                                          )
                                }}
                            </Badge>
                        </div>
                    </div>

                    <div
                        v-if="
                            canForObjectType(
                                props.objectType.slug,
                                'rules.manage',
                            )
                        "
                        class="flex items-center gap-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="onToggle(rule)"
                        >
                            {{
                                rule.is_active === true
                                    ? t(
                                          'i18n.pages.settings.notification_rules.disable',
                                      )
                                    : t(
                                          'i18n.pages.settings.notification_rules.enable',
                                      )
                            }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="openEdit(rule)"
                        >
                            {{
                                t('i18n.pages.settings.notification_rules.edit')
                            }}
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Dialog v-model:open="editorOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editingRule
                                ? t(
                                      'i18n.pages.settings.notification_rules.edit_rule',
                                  )
                                : t(
                                      'i18n.pages.settings.notification_rules.new_rule',
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
    </div>
</template>
