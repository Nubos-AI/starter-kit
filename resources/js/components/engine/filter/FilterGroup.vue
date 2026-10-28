<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFilterTreeContext } from '@/composables/useFilterTree';
import type { Combinator, FilterTreeGroup } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import FilterCondition from './FilterCondition.vue';

const { t } = useI18n();

defineOptions({ name: 'FilterGroup' });

const props = defineProps<{
    node: FilterTreeGroup;
    depth: number;
}>();

const context = useFilterTreeContext();

const combinators: { value: Combinator; label: string }[] = [
    {
        value: 'and',
        label: t('i18n.components.engine.filter.filter_group.and'),
    },
    {
        value: 'or',
        label: t('i18n.components.engine.filter.filter_group.or'),
    },
];

const isRoot = computed(() => props.depth === 0);

const addConditionDisabled = computed(
    () => context.nodeCount.value >= context.maxNodes,
);

const addGroupDisabled = computed(() => props.depth + 1 >= context.maxDepth);
</script>

<template>
    <div
        data-filter-group
        :data-depth="depth"
        class="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 p-3"
    >
        <div class="flex flex-wrap items-center gap-2">
            <div
                class="flex items-center gap-1"
                role="group"
                :aria-label="
                    t(
                        'i18n.components.engine.filter.filter_group.logical_operator',
                    )
                "
            >
                <Badge
                    v-for="option in combinators"
                    :key="option.value"
                    :variant="
                        node.combinator === option.value ? 'default' : 'outline'
                    "
                    class="cursor-pointer select-none"
                    role="button"
                    tabindex="0"
                    :aria-pressed="node.combinator === option.value"
                    @click="context.setCombinator(node.id, option.value)"
                    @keydown.enter.prevent="
                        context.setCombinator(node.id, option.value)
                    "
                    @keydown.space.prevent="
                        context.setCombinator(node.id, option.value)
                    "
                >
                    {{ option.label }}
                </Badge>
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                data-add-condition
                :disabled="addConditionDisabled"
                @click="context.addCondition(node.id)"
            >
                {{
                    t(
                        'i18n.components.engine.filter.filter_group.add_condition',
                    )
                }}
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-add-group
                :disabled="addGroupDisabled"
                @click="context.addGroup(node.id)"
            >
                {{ t('i18n.components.engine.filter.filter_group.add_group') }}
            </Button>

            <Button
                v-if="!isRoot"
                type="button"
                variant="plain"
                size="icon-sm"
                class="ml-auto icon-danger hover:icon-danger-hovered"
                data-remove-node
                :aria-label="
                    t('i18n.components.engine.filter.filter_group.remove_group')
                "
                @click="context.removeNode(node.id)"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>

        <div class="flex flex-col gap-2 pl-3">
            <template v-for="child in node.conditions" :key="child.id">
                <FilterGroup
                    v-if="child.kind === 'group'"
                    :node="child"
                    :depth="depth + 1"
                />
                <FilterCondition v-else :node="child" />
            </template>
        </div>
    </div>
</template>
