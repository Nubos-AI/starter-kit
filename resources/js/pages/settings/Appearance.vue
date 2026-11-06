<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    <div class="flex flex-col gap-6">
        <Head :title="t('i18n.pages.settings.appearance.appearance')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.settings.appearance.appearance')"
            :description="
                t(
                    'i18n.pages.settings.appearance.these_settings_apply_only_to_you_and_are_preserved',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.appearance.display_options')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.appearance.changes_are_saved_immediately',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
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
                    <Select v-model="density">
                        <SelectTrigger
                            id="appearance-density"
                            data-density-select
                        >
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
                    <Select v-model="pageSize">
                        <SelectTrigger
                            id="appearance-page-size"
                            data-page-size-select
                        >
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
                        t(
                            'i18n.pages.settings.appearance.home_page_after_sign_in',
                        )
                    }}</Label>
                    <Select v-model="startPage">
                        <SelectTrigger
                            id="appearance-start-page"
                            data-start-page-select
                        >
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
            </CardContent>
        </Card>
    </div>
</template>
