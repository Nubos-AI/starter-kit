<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PreferencePolicyController from '@/actions/App/Http/Controllers/Tenancy/PreferencePolicyController';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { PreferencePolicy } from '@/shared/types/preferences';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormSwitch from '@/shared/ui/FormSwitch.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent } from '@/shared/ui/primitives/card';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

const { t } = useI18n();

interface PolicyCategory {
  value: string;
  label: string;
  areas: string[];
}

interface PolicyEntry {
  category: string;
  label: string;
  hint: string;
}

interface PolicyArea {
  value: string;
  label: string;
  hint: string;
  entries: PolicyEntry[];
}

const PAGE_DESCRIPTION = t(
  'i18n.pages.organisation.preference_policy.choose_which_interface_settings_are_remembered_for_each_user',
);

const AREA_ORDER: string[] = ['records', 'configuration', 'global'];

const AREA_LABELS: Record<string, string> = {
  records: t('i18n.pages.organisation.preference_policy.records'),
  configuration: t('i18n.pages.organisation.preference_policy.configuration'),
  global: t('i18n.pages.organisation.preference_policy.entire_interface'),
};

const AREA_HINTS: Record<string, string> = {
  records: t('i18n.pages.organisation.preference_policy.applies_to_record_lists_boards_and_detail_pages'),
  configuration: t('i18n.pages.organisation.preference_policy.applies_to_configuration_and_administration_lists'),
  global: t('i18n.pages.organisation.preference_policy.applies_throughout_the_application_regardless_of_area'),
};

const CATEGORY_HINTS: Record<string, string> = {
  columnsAndSorting: t('i18n.pages.organisation.preference_policy.visible_columns_their_order_and_width_and_sorting'),
  viewMode: t('i18n.pages.organisation.preference_policy.table_or_board_including_the_selected_board_axis'),
  filterAndSegment: t('i18n.pages.organisation.preference_policy.last_used_segment_and_filter'),
  layoutAndAppearance: t('i18n.pages.organisation.preference_policy.appearance_density_page_size_and_sidebar_state'),
  panelState: t('i18n.pages.organisation.preference_policy.which_panels_on_a_record_page_are_collapsed_when'),
  panelVisibility: t('i18n.pages.organisation.preference_policy.which_panels_on_a_record_page_are_hidden_through'),
};

const props = defineProps<{
  policy: PreferencePolicy;
  categories: PolicyCategory[];
  canUpdate: boolean;
}>();

const switches = ref<Record<string, Record<string, boolean>>>(
  Object.fromEntries(
    props.categories.map((category) => [
      category.value,
      Object.fromEntries(
        category.areas.map((area) => [area, props.policy[category.value as keyof PreferencePolicy]?.[area] ?? true]),
      ),
    ]),
  ),
);

const areas = computed<PolicyArea[]>(() =>
  AREA_ORDER.map((area): PolicyArea => ({
    value: area,
    label: AREA_LABELS[area] ?? area,
    hint: AREA_HINTS[area] ?? '',
    entries: props.categories
      .filter((category) => category.areas.includes(area))
      .map((category): PolicyEntry => ({
        category: category.value,
        label: category.label,
        hint: CATEGORY_HINTS[category.value] ?? '',
      })),
  })).filter((area) => area.entries.length > 0),
);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({ switches: switches.value }),
  backHref: PreferencePolicyController.edit.url(),
});

const form = useForm<{
  preference_policy: Record<string, Record<string, boolean>>;
}>({ preference_policy: switches.value });

const save = (): void => {
  form
    .transform(() => ({ preference_policy: switches.value }))
    .put(PreferencePolicyController.update.url(), { preserveScroll: true, onSuccess: markSaved });
};
</script>

<template>
  <PageShell :title="t('i18n.pages.organisation.preference_policy.personalisation')" :description="PAGE_DESCRIPTION">
    <ReadOnlyNotice
      v-if="!canUpdate"
      :reason="t('i18n.pages.organisation.preference_policy.you_may_view_these_settings_but_not_change_them')"
      data-policy-read-only
    />

    <Card>
      <CardContent class="flex flex-col gap-6">
        <CardSection
          v-for="area in areas"
          :key="area.value"
          :title="area.label"
          :description="area.hint"
          :data-policy-area="area.value"
        >
          <FormSwitch
            v-for="entry in area.entries"
            :id="`${entry.category}-${area.value}`"
            :key="entry.category"
            v-model="switches[entry.category][area.value]"
            :label="entry.label"
            :description="entry.hint"
            :disabled="!canUpdate"
            :data-policy-switch="`${entry.category}.${area.value}`"
          />
        </CardSection>

        <FormActions
          v-if="canUpdate"
          :dirty="isDirty"
          :processing="form.processing"
          type="button"
          @save="save"
          @cancel="requestLeave"
        />
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
