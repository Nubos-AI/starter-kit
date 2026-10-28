<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TeamAccessRulesController from '@/actions/App/Http/Controllers/Teams/TeamAccessRulesController';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import FormActions from '@/components/FormActions.vue';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { FieldDefinition } from '@/types/fields';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

interface AccessRuleObjectType {
    id: string;
    slug: string;
    name: string;
    fields: FieldDefinition[];
}

interface AccessRule {
    objectTypeId: string;
    filterDefinition: FilterGroupNode;
    isActive: boolean;
    inheritance: string;
}

interface InheritedRule {
    objectTypeId: string;
    teamName: string;
    filterDefinition: FilterGroupNode;
    inheritance: string;
}

const props = defineProps<{
    team: { id: string; name: string };
    objectTypes: AccessRuleObjectType[];
    rules: AccessRule[];
    inheritedRules: InheritedRule[];
    inheritanceOptions: SelectOption[];
}>();

const trees = ref<Record<string, FilterGroupNode | undefined>>(
    Object.fromEntries(
        props.objectTypes.map((type) => [
            type.id,
            props.rules.find((rule) => rule.objectTypeId === type.id)
                ?.filterDefinition,
        ]),
    ),
);

const active = ref<Record<string, boolean>>(
    Object.fromEntries(
        props.objectTypes.map((type) => [
            type.id,
            props.rules.find((rule) => rule.objectTypeId === type.id)
                ?.isActive ?? true,
        ]),
    ),
);

const inheritance = ref<Record<string, string>>(
    Object.fromEntries(
        props.objectTypes.map((type) => [
            type.id,
            props.rules.find((rule) => rule.objectTypeId === type.id)
                ?.inheritance ?? 'intersect',
        ]),
    ),
);

const savedRuleIds = computed<string[]>(() =>
    props.rules.map((rule) => rule.objectTypeId),
);

const selectedTypeId = ref<string | null>(
    props.rules[0]?.objectTypeId ?? props.objectTypes[0]?.id ?? null,
);

const typeChoices = computed<SelectOption[]>(() =>
    props.objectTypes.map((type) => ({
        value: type.id,
        label: type.name,
        description: type.slug,
        badge: savedRuleIds.value.includes(type.id)
            ? t('i18n.pages.teams.access_rules.rule')
            : undefined,
    })),
);

const selectedType = computed<AccessRuleObjectType | undefined>(() =>
    props.objectTypes.find((type) => type.id === selectedTypeId.value),
);

const ruledTypes = computed<AccessRuleObjectType[]>(() =>
    props.objectTypes.filter((type) => savedRuleIds.value.includes(type.id)),
);

function conditionCount(tree: FilterGroupNode | undefined): number {
    if (tree === undefined) {
        return 0;
    }

    return tree.conditions.reduce<number>(
        (total, child) =>
            total + ('conditions' in child ? conditionCount(child) : 1),
        0,
    );
}

const selectedTree = computed<FilterGroupNode | undefined>({
    get: () =>
        selectedTypeId.value === null
            ? undefined
            : trees.value[selectedTypeId.value],
    set: (tree) => {
        if (selectedTypeId.value !== null && tree !== undefined) {
            trees.value[selectedTypeId.value] = tree;
        }
    },
});

function inheritedFor(objectTypeId: string): InheritedRule[] {
    return props.inheritedRules.filter(
        (rule) => rule.objectTypeId === objectTypeId,
    );
}

function describe(tree: FilterGroupNode): string {
    const parts = tree.conditions.map((child) =>
        'conditions' in child
            ? `(${describe(child)})`
            : `${child.field} ${child.operator} ${String(child.value ?? '')}`.trim(),
    );

    return parts.join(
        tree.combinator === 'or'
            ? t('i18n.pages.teams.access_rules.or')
            : t('i18n.pages.teams.access_rules.and'),
    );
}

const teamHref = TeamsController.edit.url({ team: props.team.id });

usePageBreadcrumbs(() => [
    { title: props.team.name, href: teamHref },
    { title: t('i18n.pages.teams.access_rules.access_rules') },
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
        trees: trees.value,
        active: active.value,
        inheritance: inheritance.value,
    }),
    backHref: teamHref,
});

const form = useForm<{
    filter_definition: FilterGroupNode;
    is_active: boolean;
    inheritance: string;
}>({
    filter_definition: { combinator: 'and', conditions: [] },
    is_active: true,
    inheritance: 'intersect',
});

function save(objectTypeId: string): void {
    form.filter_definition = trees.value[objectTypeId] ?? {
        combinator: 'and',
        conditions: [],
    };
    form.is_active = active.value[objectTypeId] ?? true;
    form.inheritance = inheritance.value[objectTypeId] ?? 'intersect';

    form.put(
        TeamAccessRulesController.update.url({
            team: props.team.id,
            objectType: objectTypeId,
        }),
        { preserveScroll: true, onSuccess: markSaved },
    );
}

function remove(objectTypeId: string): void {
    router.delete(
        TeamAccessRulesController.destroy.url({
            team: props.team.id,
            objectType: objectTypeId,
        }),
        { preserveScroll: true, onSuccess: markSaved },
    );
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3">
        <Head
            :title="
                t('i18n.pages.teams.access_rules.access_rules_2', {
                    value1: team.name,
                })
            "
        />

        <Heading
            :title="t('i18n.pages.teams.access_rules.access_rules')"
            :description="
                t(
                    'i18n.pages.teams.access_rules.members_of_can_only_see_records_matching_all_conditions',
                    { value1: team.name },
                )
            "
        />

        <p
            v-if="objectTypes.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'i18n.pages.teams.access_rules.there_are_no_object_types_for_which_you_may',
                )
            }}
        </p>

        <Card v-if="objectTypes.length > 0">
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.teams.access_rules.object_type')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.teams.access_rules.select_the_object_type_whose_rule_you_want_to',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="access-rule-object-type">{{
                        t('i18n.pages.teams.access_rules.object_type')
                    }}</Label>
                    <Combobox
                        id="access-rule-object-type"
                        v-model="selectedTypeId"
                        :options="typeChoices"
                        :search-placeholder="
                            t(
                                'i18n.pages.teams.access_rules.search_object_types',
                            )
                        "
                        :empty-label="
                            t(
                                'i18n.pages.teams.access_rules.there_are_no_object_types',
                            )
                        "
                        :aria-label="
                            t('i18n.pages.teams.access_rules.object_type')
                        "
                        data-access-rule-type-select
                    />
                </div>

                <div
                    v-if="ruledTypes.length > 0"
                    class="flex flex-wrap gap-1.5"
                >
                    <span class="text-xs text-muted-foreground">
                        {{ t('i18n.pages.teams.access_rules.rules_exist_for') }}
                    </span>
                    <Badge
                        v-for="type in ruledTypes"
                        :key="type.id"
                        variant="secondary"
                        class="cursor-pointer"
                        :data-access-rule-jump="type.slug"
                        @click="selectedTypeId = type.id"
                    >
                        {{ type.name }}
                    </Badge>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    {{
                        t(
                            'i18n.pages.teams.access_rules.this_team_has_no_rule_yet_members_see_all',
                        )
                    }}
                </p>
            </CardContent>
        </Card>

        <Card
            v-if="selectedType"
            :key="selectedType.id"
            :data-access-rule-card="selectedType.slug"
        >
            <CardHeader>
                <CardTitle>{{ selectedType.name }}</CardTitle>
                <CardDescription>
                    <template v-if="savedRuleIds.includes(selectedType.id)">
                        {{
                            t(
                                'i18n.pages.teams.access_rules.rule_active_members_see_only_matching_records',
                            )
                        }}
                    </template>
                    <template v-else>
                        {{
                            t(
                                'i18n.pages.teams.access_rules.no_rule_members_see_all_records_of_this_object',
                            )
                        }}
                    </template>
                </CardDescription>
            </CardHeader>

            <CardContent class="flex flex-col gap-4">
                <div
                    v-for="inherited in inheritedFor(selectedType.id)"
                    :key="`${inherited.teamName}-${selectedType.id}`"
                    class="rounded-md border border-dashed px-3 py-2 text-xs text-muted-foreground"
                    data-inherited-rule
                >
                    {{ t('i18n.pages.teams.access_rules.inherited_from') }}
                    <strong>{{ inherited.teamName }}</strong
                    >: {{ describe(inherited.filterDefinition) }}
                </div>

                <FilterBuilder
                    :key="selectedType.id"
                    :fields="selectedType.fields"
                    v-model="selectedTree"
                    :show-actions="false"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label :for="`inheritance-${selectedType.id}`">
                            {{
                                t(
                                    'i18n.pages.teams.access_rules.relationship_to_parent_rules',
                                )
                            }}
                        </Label>
                        <Combobox
                            :id="`inheritance-${selectedType.id}`"
                            v-model="inheritance[selectedType.id]"
                            :options="inheritanceOptions"
                            :searchable="false"
                            :aria-label="
                                t(
                                    'i18n.pages.teams.access_rules.relationship_to_parent_rules',
                                )
                            "
                        />
                    </div>

                    <div class="flex h-8 items-center gap-2 self-end">
                        <Checkbox
                            :id="`active-${selectedType.id}`"
                            v-model="active[selectedType.id]"
                            :data-access-rule-active="selectedType.slug"
                        />
                        <Label :for="`active-${selectedType.id}`">
                            {{ t('i18n.pages.teams.access_rules.rule_active') }}
                        </Label>
                    </div>
                </div>

                <div
                    v-if="savedRuleIds.includes(selectedType.id)"
                    class="flex items-center justify-end"
                >
                    <Button
                        type="button"
                        variant="destructive"
                        :data-access-rule-delete="selectedType.slug"
                        @click="remove(selectedType.id)"
                    >
                        {{ t('i18n.pages.teams.access_rules.remove_rule') }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <FormActions
            v-if="selectedType"
            :dirty="isDirty && conditionCount(selectedTree) > 0"
            type="button"
            @save="save(selectedType.id)"
            @cancel="requestLeave"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
