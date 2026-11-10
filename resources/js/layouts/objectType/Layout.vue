<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import SectionNav from '@/components/SectionNav.vue';
import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/composables/useI18n';
import type { ObjectTypeSectionItem } from '@/lib/objectTypeSections';
import { objectTypeSectionItems } from '@/lib/objectTypeSections';
import type { SectionNavEntry } from '@/types/navigation';
import type { ObjectTypeSummary } from '@/types/objectTypes';

const { t } = useI18n();

const LAYOUT_DESCRIPTION = t(
    'i18n.layouts.object_type.layout.manage_this_object_type_s_details_fields_stages_rules',
);

const CREATE_HEADING = t('i18n.layouts.object_type.layout.new_object_type');

const CREATE_DESCRIPTION = t(
    'i18n.layouts.object_type.layout.fields_stages_and_aging_rules_become_available_once_the',
);

const CREATE_SECTION_HINT = t(
    'i18n.layouts.object_type.layout.available_after_saving_the_details',
);

const SYSTEM_HINT = t(
    'i18n.layouts.object_type.layout.system_object_types_are_read_only_and_cannot_be',
);

const page = usePage();

function readObjectType(value: unknown): ObjectTypeSummary | null {
    if (typeof value !== 'object' || value === null || !('slug' in value)) {
        return null;
    }

    return value as ObjectTypeSummary;
}

const objectType = computed<ObjectTypeSummary | null>(() =>
    readObjectType(page.props.objectType),
);

const isCreating = computed<boolean>(() => page.props.mode === 'create');

const sectionItems = computed<ObjectTypeSectionItem[]>(() => {
    if (objectType.value !== null) {
        return objectTypeSectionItems(objectType.value.slug);
    }

    return isCreating.value ? objectTypeSectionItems(null) : [];
});

const sections = computed<SectionNavEntry[]>(() =>
    sectionItems.value.map((item) => ({
        key: item.title,
        title: item.title,
        href: item.href,
        disabledReason: item.href === null ? CREATE_SECTION_HINT : null,
    })),
);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-6 p-3">
        <div v-if="objectType !== null" class="flex items-center gap-3">
            <Heading
                variant="small"
                :title="objectType.name"
                :description="LAYOUT_DESCRIPTION"
            />
            <Badge v-if="objectType.is_system" variant="secondary">
                {{ t('i18n.layouts.object_type.layout.system') }}
            </Badge>
        </div>

        <Heading
            v-else-if="isCreating"
            variant="small"
            :title="CREATE_HEADING"
            :description="CREATE_DESCRIPTION"
        />

        <p
            v-if="objectType?.is_system"
            class="rounded-md border border-border bg-muted px-4 py-3 text-sm text-muted-foreground"
        >
            {{ SYSTEM_HINT }}
        </p>

        <SectionNav
            :entries="sections"
            :label="t('i18n.layouts.object_type.layout.object_type')"
            data-object-type-sections
        >
            <template #append>
                <UiExtensionPoint
                    name="object-types.navigation"
                    :context="{ objectType }"
                />
            </template>

            <slot />
        </SectionNav>
    </div>
</template>
