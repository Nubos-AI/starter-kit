<script setup lang="ts">
import { computed } from 'vue';
import SimpleTable from '@/components/data-grid/SimpleTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { MergeFieldPlan, MergeValueOrigin } from '@/types/merge';
import { MERGE_FIELD_STRATEGY_LABELS } from '@/types/merge';

const { t } = useI18n();

const EMPTY_VALUE = '—';

const CONFLICT_LABEL = t(
    'i18n.components.engine.records.merge_field_resolution.conflict',
);

const DECISION_LABEL = t(
    'i18n.components.engine.records.merge_field_resolution.decision_required',
);

const OVERRIDDEN_LABEL = t(
    'i18n.components.engine.records.merge_field_resolution.chosen_by_you',
);

const props = defineProps<{
    fields: MergeFieldPlan[];
    overrides: Record<string, 'target' | 'source'>;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    choose: [key: string, origin: 'target' | 'source'];
    reset: [key: string];
}>();

const sortedFields = computed<MergeFieldPlan[]>(() => {
    const weight = (field: MergeFieldPlan): number => {
        if (field.requires_decision) {
            return 0;
        }

        return field.is_conflict ? 1 : 2;
    };

    return [...props.fields].sort(
        (left, right) => weight(left) - weight(right),
    );
});

function display(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return EMPTY_VALUE;
    }

    if (Array.isArray(value)) {
        return value.length === 0 ? EMPTY_VALUE : value.join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function isChosen(field: MergeFieldPlan, origin: MergeValueOrigin): boolean {
    return props.overrides[field.key] === origin;
}

function strategyLabel(field: MergeFieldPlan): string {
    return MERGE_FIELD_STRATEGY_LABELS[field.strategy];
}

function onChoose(field: MergeFieldPlan, origin: 'target' | 'source'): void {
    if (isChosen(field, origin)) {
        emit('reset', field.key);

        return;
    }

    emit('choose', field.key, origin);
}
</script>

<template>
    <div class="overflow-x-auto" data-merge-resolution>
        <SimpleTable class="min-w-[48rem]">
            <thead>
                <tr>
                    <th>
                        {{
                            t(
                                'i18n.components.engine.records.merge_field_resolution.field',
                            )
                        }}
                    </th>
                    <th>
                        {{
                            t(
                                'i18n.components.engine.records.merge_field_resolution.goal',
                            )
                        }}
                    </th>
                    <th>
                        {{
                            t(
                                'i18n.components.engine.records.merge_field_resolution.source',
                            )
                        }}
                    </th>
                    <th>
                        {{
                            t(
                                'i18n.components.engine.records.merge_field_resolution.result',
                            )
                        }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="field in sortedFields"
                    :key="field.key"
                    class="border-b align-top"
                    :data-merge-field="field.key"
                >
                    <td>
                        <div class="flex flex-col gap-1">
                            <span class="font-medium">{{ field.label }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ strategyLabel(field) }}
                            </span>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-if="field.requires_decision"
                                    variant="destructive"
                                    data-merge-decision-badge
                                >
                                    {{ DECISION_LABEL }}
                                </Badge>
                                <Badge
                                    v-else-if="field.is_conflict"
                                    variant="warning"
                                    data-merge-conflict-badge
                                >
                                    {{ CONFLICT_LABEL }}
                                </Badge>
                                <Badge
                                    v-if="field.is_overridden"
                                    variant="outline"
                                    data-merge-override-badge
                                >
                                    {{ OVERRIDDEN_LABEL }}
                                </Badge>
                            </div>
                        </div>
                    </td>
                    <td>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                isChosen(field, 'target')
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="props.disabled"
                            :data-merge-choose-target="field.key"
                            @click="onChoose(field, 'target')"
                        >
                            {{ display(field.target_value) }}
                        </Button>
                    </td>
                    <td>
                        <Button
                            type="button"
                            size="sm"
                            :variant="
                                isChosen(field, 'source')
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="props.disabled"
                            :data-merge-choose-source="field.key"
                            @click="onChoose(field, 'source')"
                        >
                            {{ display(field.source_value) }}
                        </Button>
                    </td>
                    <td :data-merge-result="field.key">
                        {{ display(field.result_value) }}
                    </td>
                </tr>
            </tbody>
        </SimpleTable>
    </div>
</template>
