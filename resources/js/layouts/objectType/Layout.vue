<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useI18n } from '@/composables/useI18n';
import type { ObjectTypeSectionItem } from '@/lib/objectTypeSections';
import { objectTypeSectionItems } from '@/lib/objectTypeSections';
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

const sections = computed<ObjectTypeSectionItem[]>(() => {
    if (objectType.value !== null) {
        return objectTypeSectionItems(objectType.value.slug);
    }

    return isCreating.value ? objectTypeSectionItems(null) : [];
});

const { isCurrentUrl } = useCurrentUrl();
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
            class="rounded-md border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
        >
            {{ SYSTEM_HINT }}
        </p>

        <div class="flex min-h-0 flex-1 flex-col gap-6 lg:flex-row lg:gap-8">
            <aside v-if="sections.length > 0" class="lg:w-56 lg:shrink-0">
                <nav
                    class="flex gap-1 overflow-x-auto lg:sticky lg:top-6 lg:flex-col lg:space-y-1 lg:overflow-visible"
                    :aria-label="
                        t('i18n.layouts.object_type.layout.object_type')
                    "
                    data-object-type-sections
                >
                    <template v-for="item in sections" :key="item.title">
                        <Button
                            v-if="item.href !== null"
                            variant="ghost"
                            :class="[
                                'shrink-0 justify-start lg:w-full',
                                { 'bg-muted': isCurrentUrl(item.href) },
                            ]"
                            data-section
                            :data-section-active="
                                isCurrentUrl(item.href) ? '' : undefined
                            "
                            as-child
                        >
                            <Link :href="item.href">{{ item.title }}</Link>
                        </Button>

                        <Button
                            v-else
                            variant="ghost"
                            class="shrink-0 justify-start lg:w-full"
                            disabled
                            data-section
                            data-section-disabled
                            :title="CREATE_SECTION_HINT"
                        >
                            {{ item.title }}
                        </Button>
                    </template>
                    <UiExtensionPoint
                        name="object-types.navigation"
                        :context="{ objectType }"
                    />
                </nav>
            </aside>

            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col gap-6 overflow-y-auto"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
