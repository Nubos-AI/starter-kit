<script setup lang="ts">
import { watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useFilterTree } from '@/composables/useFilterTree';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import type { FieldDefinition } from '@/types/fields';
import FilterGroup from './FilterGroup.vue';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        fields: FieldDefinition[];
        modelValue?: FilterGroupNode;
        showActions?: boolean;
    }>(),
    { showActions: true },
);

const emit = defineEmits<{
    apply: [tree: FilterGroupNode];
    reset: [];
    'update:modelValue': [tree: FilterGroupNode];
}>();

const tree = useFilterTree(props.fields, props.modelValue);

tree.provideContext();

watch(
    tree.root,
    () => {
        emit('update:modelValue', tree.serialize());
    },
    { deep: true, immediate: true },
);

function onApply(): void {
    emit('apply', tree.serialize());
}

function onReset(): void {
    tree.reset();
    emit('reset');
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p
            v-if="tree.nodeCount.value === 0"
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'i18n.components.engine.filter.filter_builder.no_conditions_yet',
                )
            }}
        </p>
        <FilterGroup :node="tree.root.value" :depth="0" />

        <div v-if="showActions" class="flex items-center gap-2">
            <Button type="button" variant="default" @click="onApply">
                {{ t('i18n.components.engine.filter.filter_builder.apply') }}
            </Button>
            <Button type="button" variant="outline" @click="onReset">
                {{ t('i18n.components.engine.filter.filter_builder.reset') }}
            </Button>
        </div>
    </div>
</template>
