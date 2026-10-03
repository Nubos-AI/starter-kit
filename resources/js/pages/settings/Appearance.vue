<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useUserPreferences } from '@/composables/useUserPreferences';
import type { GridDensity } from '@/types/preferences';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    startPageOptions: SelectOption[];
}>();

const { settings, patch } = useUserPreferences();

const DASHBOARD_VALUE = 'dashboard';

const densityOptions: SelectOption[] = [
    {
        value: 'compact',
        label: t('i18n.pages.settings.appearance.compact'),
    },
    {
        value: 'comfortable',
        label: t('i18n.pages.settings.appearance.comfortable'),
    },
];

const pageSizeOptions: SelectOption[] = [
    { value: '25', label: t('i18n.pages.settings.appearance.25_rows') },
    { value: '50', label: t('i18n.pages.settings.appearance.50_rows') },
    { value: '100', label: t('i18n.pages.settings.appearance.100_rows') },
    { value: '200', label: t('i18n.pages.settings.appearance.200_rows') },
];

const startPageChoices = computed<SelectOption[]>(() => [
    {
        value: DASHBOARD_VALUE,
        label: t('i18n.pages.settings.appearance.dashboard'),
    },
    ...props.startPageOptions,
]);

const density = computed<string>({
    get: () => settings.value.density,
    set: (value) => patch({ settings: { density: value as GridDensity } }),
});

const pageSize = computed<string>({
    get: () => String(settings.value.pageSize),
    set: (value) => patch({ settings: { pageSize: Number(value) } }),
});

const startPage = computed<string>({
    get: () => settings.value.startObjectTypeId ?? DASHBOARD_VALUE,
    set: (value) =>
        patch({
            settings: {
                startObjectTypeId: value === DASHBOARD_VALUE ? null : value,
            },
        }),
});
</script>

<template>
    <Head :title="t('i18n.pages.settings.appearance.appearance')" />

    <h1 class="sr-only">
        {{ t('i18n.pages.settings.appearance.appearance') }}
    </h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('i18n.pages.settings.appearance.appearance')"
            :description="
                t(
                    'i18n.pages.settings.appearance.these_settings_apply_only_to_you_and_are_preserved',
                )
            "
        />

        <div class="grid gap-2">
            <Label>{{
                t('i18n.pages.settings.appearance.colour_scheme')
            }}</Label>
            <AppearanceTabs />
        </div>

        <div class="grid gap-2 sm:max-w-sm">
            <Label for="appearance-density">{{
                t('i18n.pages.settings.appearance.display_density')
            }}</Label>
            <Select id="appearance-density" v-model="density">
                <SelectTrigger data-density-select>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in densityOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <span class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.pages.settings.appearance.determines_row_height_and_font_size_in_all_lists',
                    )
                }}
            </span>
        </div>

        <div class="grid gap-2 sm:max-w-sm">
            <Label for="appearance-page-size">{{
                t('i18n.pages.settings.appearance.rows_per_load')
            }}</Label>
            <Select id="appearance-page-size" v-model="pageSize">
                <SelectTrigger data-page-size-select>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in pageSizeOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <span class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.pages.settings.appearance.how_many_records_are_loaded_each_time_you_scroll',
                    )
                }}
            </span>
        </div>

        <div class="grid gap-2 sm:max-w-sm">
            <Label for="appearance-start-page">{{
                t('i18n.pages.settings.appearance.home_page_after_sign_in')
            }}</Label>
            <Select id="appearance-start-page" v-model="startPage">
                <SelectTrigger data-start-page-select>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in startPageChoices"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
    </div>
</template>
