<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PreferencePolicyController from '@/actions/App/Http/Controllers/Tenancy/PreferencePolicyController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { PreferencePolicy } from '@/types/preferences';

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
    records: t(
        'i18n.pages.organisation.preference_policy.applies_to_record_lists_boards_and_detail_pages',
    ),
    configuration: t(
        'i18n.pages.organisation.preference_policy.applies_to_configuration_and_administration_lists',
    ),
    global: t(
        'i18n.pages.organisation.preference_policy.applies_throughout_the_application_regardless_of_area',
    ),
};

const CATEGORY_HINTS: Record<string, string> = {
    columnsAndSorting: t(
        'i18n.pages.organisation.preference_policy.visible_columns_their_order_and_width_and_sorting',
    ),
    viewMode: t(
        'i18n.pages.organisation.preference_policy.table_or_board_including_the_selected_board_axis',
    ),
    filterAndSegment: t(
        'i18n.pages.organisation.preference_policy.last_used_segment_and_filter',
    ),
    layoutAndAppearance: t(
        'i18n.pages.organisation.preference_policy.appearance_density_page_size_and_sidebar_state',
    ),
    panelState: t(
        'i18n.pages.organisation.preference_policy.which_panels_on_a_record_page_are_collapsed_when',
    ),
    panelVisibility: t(
        'i18n.pages.organisation.preference_policy.which_panels_on_a_record_page_are_hidden_through',
    ),
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
                category.areas.map((area) => [
                    area,
                    props.policy[category.value as keyof PreferencePolicy]?.[
                        area
                    ] ?? true,
                ]),
            ),
        ]),
    ),
);

const areas = computed<PolicyArea[]>(() =>
    AREA_ORDER.map(
        (area): PolicyArea => ({
            value: area,
            label: AREA_LABELS[area] ?? area,
            hint: AREA_HINTS[area] ?? '',
            entries: props.categories
                .filter((category) => category.areas.includes(area))
                .map(
                    (category): PolicyEntry => ({
                        category: category.value,
                        label: category.label,
                        hint: CATEGORY_HINTS[category.value] ?? '',
                    }),
                ),
        }),
    ).filter((area) => area.entries.length > 0),
);

const { isDirty, promptOpen, confirmLeave, cancelLeave, markSaved } =
    useUnsavedChanges({
        values: () => ({ switches: switches.value }),
        backHref: PreferencePolicyController.edit.url(),
    });

const form = useForm<{
    preference_policy: Record<string, Record<string, boolean>>;
}>({ preference_policy: switches.value });

const save = (): void => {
    form.transform(() => ({ preference_policy: switches.value })).put(
        PreferencePolicyController.update.url(),
        { preserveScroll: true, onSuccess: markSaved },
    );
};
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head
            :title="
                t('i18n.pages.organisation.preference_policy.personalisation')
            "
        />

        <Heading
            variant="small"
            :title="
                t('i18n.pages.organisation.preference_policy.personalisation')
            "
            :description="PAGE_DESCRIPTION"
        />

        <div class="grid items-start gap-4 md:grid-cols-2 2xl:grid-cols-3">
            <Card
                v-for="area in areas"
                :key="area.value"
                :data-policy-area="area.value"
            >
                <CardHeader>
                    <CardTitle>{{ area.label }}</CardTitle>
                    <CardDescription>{{ area.hint }}</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div
                        v-for="entry in area.entries"
                        :key="entry.category"
                        class="flex items-start justify-between gap-4"
                    >
                        <div class="grid gap-0.5 leading-none">
                            <Label :for="`${entry.category}-${area.value}`">
                                {{ entry.label }}
                            </Label>
                            <span class="text-xs text-muted-foreground">
                                {{ entry.hint }}
                            </span>
                        </div>

                        <Switch
                            :id="`${entry.category}-${area.value}`"
                            class="mt-0.5 shrink-0"
                            :disabled="!canUpdate"
                            :data-policy-switch="`${entry.category}.${area.value}`"
                            v-model="switches[entry.category][area.value]"
                        />
                    </div>
                </CardContent>
            </Card>
        </div>

        <FormActions
            v-if="canUpdate"
            :dirty="isDirty"
            :processing="form.processing"
            type="button"
            :cancellable="false"
            @save="save"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
