<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { FieldTypeOption } from '@/types/fields';

const { t } = useI18n();

const props = defineProps<{
    fieldTypes: FieldTypeOption[];
}>();

const emit = defineEmits<{
    select: [value: string];
}>();

interface FieldTypeGroup {
    key: string;
    label: string;
    position: number;
    options: FieldTypeOption[];
}

const groups = computed<FieldTypeGroup[]>(() => {
    const byCategory = new Map<string, FieldTypeGroup>();

    for (const option of props.fieldTypes) {
        const group = byCategory.get(option.category) ?? {
            key: option.category,
            label: option.categoryLabel,
            position: option.categoryPosition,
            options: [],
        };

        group.options.push(option);
        byCategory.set(option.category, group);
    }

    return [...byCategory.values()].sort((a, b) => a.position - b.position);
});
</script>

<template>
    <div
        data-field-type-picker
        class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-4"
    >
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'i18n.components.engine.object_type.field_type_picker.what_should_this_field_contain',
                )
            }}
        </p>

        <section
            v-for="group in groups"
            :key="group.key"
            :data-field-type-group="group.key"
            class="grid gap-2"
        >
            <span class="text-sm leading-none font-medium">
                {{ group.label }}
            </span>
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    v-for="option in group.options"
                    :key="option.value"
                    type="button"
                    :data-field-type-option="option.value"
                    class="grid gap-0.5 rounded-md border border-input p-3 text-left hover:border-ring hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                    @click="emit('select', option.value)"
                >
                    <span class="text-sm font-medium">{{ option.label }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ option.description }}
                    </span>
                </button>
            </div>
        </section>
    </div>
</template>
