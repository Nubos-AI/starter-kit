<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import TeamAccessRulesController from '@/actions/App/Http/Controllers/Teams/TeamAccessRulesController';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import { useI18n } from '@/shared/composables/useI18n';
import { useRowDeletion } from '@/shared/composables/useRowDeletion';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import type { SelectOption } from '@/shared/types/ui';
import CardSection from '@/shared/ui/CardSection.vue';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import FilterBuilder from '../../../../../../shared/filters/ui/FilterBuilder.vue';
import { useTeamSection } from '../../../composables/useTeamSection';

const { t } = useI18n();

interface AccessRuleObjectType {
  id: string;
  slug: string;
  name: string;
  label: string;
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
  canManage: boolean;
  manageReason: string | null;
}>();

const trees = ref<Record<string, FilterGroupNode | undefined>>(
  Object.fromEntries(
    props.objectTypes.map((type) => [
      type.id,
      props.rules.find((rule) => rule.objectTypeId === type.id)?.filterDefinition,
    ]),
  ),
);

const active = ref<Record<string, boolean>>(
  Object.fromEntries(
    props.objectTypes.map((type) => [
      type.id,
      props.rules.find((rule) => rule.objectTypeId === type.id)?.isActive ?? true,
    ]),
  ),
);

const inheritance = ref<Record<string, string>>(
  Object.fromEntries(
    props.objectTypes.map((type) => [
      type.id,
      props.rules.find((rule) => rule.objectTypeId === type.id)?.inheritance ?? 'intersect',
    ]),
  ),
);

const savedRuleIds = computed<string[]>(() => props.rules.map((rule) => rule.objectTypeId));

const selectedTypeId = ref<string | null>(props.rules[0]?.objectTypeId ?? props.objectTypes[0]?.id ?? null);

const typeChoices = computed<SelectOption[]>(() =>
  props.objectTypes.map((type) => ({
    value: type.id,
    label: type.label,
    description: type.slug,
    badge: savedRuleIds.value.includes(type.id) ? t('i18n.pages.teams.access_rules.rule') : undefined,
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
    (total, child) => total + ('conditions' in child ? conditionCount(child) : 1),
    0,
  );
}

const selectedTree = computed<FilterGroupNode | undefined>({
  get: () => (selectedTypeId.value === null ? undefined : trees.value[selectedTypeId.value]),
  set: (tree) => {
    if (selectedTypeId.value !== null && tree !== undefined) {
      trees.value[selectedTypeId.value] = tree;
    }
  },
});

function inheritedFor(objectTypeId: string): InheritedRule[] {
  return props.inheritedRules.filter((rule) => rule.objectTypeId === objectTypeId);
}

function describe(tree: FilterGroupNode): string {
  const parts = tree.conditions.map((child) =>
    'conditions' in child
      ? `(${describe(child)})`
      : `${child.field} ${child.operator} ${String(child.value ?? '')}`.trim(),
  );

  return parts.join(
    tree.combinator === 'or' ? t('i18n.pages.teams.access_rules.or') : t('i18n.pages.teams.access_rules.and'),
  );
}

const teamHref = TeamsController.edit.url({ team: props.team.id });

useTeamSection(props.team, 'accessRules');

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
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

const {
  pendingDelete: pendingRemoval,
  deletePending: removalPending,
  deleteDialogOpen: removalOpen,
  requestDelete: requestRemoval,
  confirmDelete: confirmRemoval,
} = useRowDeletion<AccessRuleObjectType>({
  url: (objectType) =>
    TeamAccessRulesController.destroy.url({
      team: props.team.id,
      objectType: objectType.id,
    }),
  onSuccess: markSaved,
});
</script>

<template>
  <div class="flex flex-col gap-6">
    <Head
      :title="
        t('i18n.pages.teams.access_rules.access_rules_2', {
          value1: team.name,
        })
      "
    />

    <p class="text-sm text-muted-foreground">
      {{
        t('i18n.pages.teams.access_rules.members_of_can_only_see_records_matching_all_conditions', {
          value1: team.name,
        })
      }}
    </p>

    <ReadOnlyNotice v-if="!canManage && manageReason !== null" :reason="manageReason" data-access-rules-read-only />

    <EmptyState
      v-if="objectTypes.length === 0"
      variant="inline"
      :description="t('i18n.pages.teams.access_rules.there_are_no_object_types_for_which_you_may')"
    />

    <Card v-if="objectTypes.length > 0" data-access-rules>
      <CardContent class="flex flex-col gap-6">
        <CardSection
          :title="t('i18n.pages.teams.access_rules.object_type')"
          :description="t('i18n.pages.teams.access_rules.select_the_object_type_whose_rule_you_want_to')"
          data-access-rule-section="object-type"
        >
          <FormField
            for="access-rule-object-type"
            :label="t('i18n.pages.teams.access_rules.object_type')"
            class="sm:max-w-sm"
          >
            <Combobox
              id="access-rule-object-type"
              v-model="selectedTypeId"
              :options="typeChoices"
              :search-placeholder="t('i18n.pages.teams.access_rules.search_object_types')"
              :empty-label="t('i18n.pages.teams.access_rules.there_are_no_object_types')"
              :aria-label="t('i18n.pages.teams.access_rules.object_type')"
              data-access-rule-type-select
            />
          </FormField>

          <div v-if="ruledTypes.length > 0" class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-muted-foreground">
              {{ t('i18n.pages.teams.access_rules.rules_exist_for') }}
            </span>
            <Button
              v-for="type in ruledTypes"
              :key="type.id"
              variant="secondary"
              size="sm"
              :data-access-rule-jump="type.slug"
              @click="selectedTypeId = type.id"
            >
              {{ type.label }}
            </Button>
          </div>
          <p v-else class="text-xs text-muted-foreground">
            {{ t('i18n.pages.teams.access_rules.this_team_has_no_rule_yet_members_see_all') }}
          </p>
        </CardSection>

        <CardSection
          v-if="selectedType"
          :key="selectedType.id"
          :title="selectedType.label"
          :description="
            savedRuleIds.includes(selectedType.id)
              ? t('i18n.pages.teams.access_rules.rule_active_members_see_only_matching_records')
              : t('i18n.pages.teams.access_rules.no_rule_members_see_all_records_of_this_object')
          "
          :data-access-rule-card="selectedType.slug"
          data-access-rule-section="rule"
        >
          <div
            v-for="inherited in inheritedFor(selectedType.id)"
            :key="`${inherited.teamName}-${selectedType.id}`"
            class="rounded-md border border-dashed px-3 py-2 text-xs text-muted-foreground"
            data-inherited-rule
          >
            {{ t('i18n.pages.teams.access_rules.inherited_from') }}
            <strong>{{ inherited.teamName }}</strong>: {{ describe(inherited.filterDefinition) }}
          </div>

          <fieldset :disabled="!canManage" class="flex flex-col gap-4" data-access-rule-controls>
            <FilterBuilder
              :key="selectedType.id"
              :fields="selectedType.fields"
              v-model="selectedTree"
              :show-actions="false"
            />
            <InputError :message="form.errors.filter_definition" data-access-rule-filter-error />

            <div class="grid gap-4 sm:grid-cols-2">
              <FormField
                :for="`inheritance-${selectedType.id}`"
                :label="t('i18n.pages.teams.access_rules.relationship_to_parent_rules')"
              >
                <Select v-model="inheritance[selectedType.id]">
                  <SelectTrigger :id="`inheritance-${selectedType.id}`" class="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="option in inheritanceOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormCheckbox
                :id="`active-${selectedType.id}`"
                v-model="active[selectedType.id]"
                :label="t('i18n.pages.teams.access_rules.rule_active')"
                :data-access-rule-active="selectedType.slug"
                class="h-10 content-center self-end"
              />
            </div>
          </fieldset>

          <FormActions
            :dirty="canManage && isDirty && conditionCount(selectedTree) > 0"
            :processing="form.processing"
            type="button"
            @save="save(selectedType.id)"
            @cancel="requestLeave"
          />
        </CardSection>
      </CardContent>
    </Card>

    <Card v-if="selectedType && savedRuleIds.includes(selectedType.id)">
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.teams.access_rules.remove_rule') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.teams.access_rules.members_see_all_records_of_this_object_type_again') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Button
          variant="destructive"
          :disabled="!canManage"
          :title="manageReason ?? undefined"
          :data-access-rule-delete="selectedType.slug"
          @click="requestRemoval(selectedType)"
        >
          {{ t('i18n.pages.teams.access_rules.remove_rule') }}
        </Button>
      </CardContent>
    </Card>

    <ConfirmDialog
      v-model:open="removalOpen"
      :title="t('i18n.pages.teams.access_rules.remove_rule_question')"
      :description="
        t('i18n.pages.teams.access_rules.the_access_rule_for_will_be_removed', { value1: pendingRemoval?.name ?? '' })
      "
      :confirm-label="t('i18n.pages.teams.access_rules.remove_rule')"
      variant="destructive"
      :pending="removalPending"
      @confirm="confirmRemoval"
    />

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </div>
</template>
