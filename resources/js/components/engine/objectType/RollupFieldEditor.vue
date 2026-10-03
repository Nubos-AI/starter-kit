<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import type { FieldDefinition } from '@/types/fields';
import type {
    RollupFieldConfig,
    RollupScope,
    RollupTargetOption,
} from '@/types/rollups';
import { rollupAggregateOptions, rollupScopeOptions } from '@/types/rollups';

const { t } = useI18n();

interface HiddenFilterInput {
    name: string;
    value: string;
}

const props = defineProps<{
    fieldType: string;
    rollupTargets: RollupTargetOption[];
    initialConfig: RollupFieldConfig | null;
    errorMessage?: string;
}>();

const controlId = useId();

const noHierarchyHint = t(
    'i18n.components.engine.object_type.rollup_field_editor.the_selected_relationship_points_at_an_object_type_without',
);

const emptyTree: FilterGroupNode = { combinator: 'and', conditions: [] };

const aggregate = ref<string>(props.initialConfig?.aggregate ?? 'sum');
const relationshipTypeId = ref<string>(
    props.initialConfig?.relationship_type_id ?? '',
);
const sourceFieldKey = ref<string>(props.initialConfig?.source_field_key ?? '');
const scope = ref<RollupScope>(props.initialConfig?.scope ?? 'direct_children');
const filter = ref<FilterGroupNode>(props.initialConfig?.filter ?? emptyTree);

const selectedTarget = computed<RollupTargetOption | null>(
    () =>
        props.rollupTargets.find(
            (target) => target.value === relationshipTypeId.value,
        ) ?? null,
);

const targetFields = computed<FieldDefinition[]>(
    () => selectedTarget.value?.fields ?? [],
);

const subtreeAvailable = computed<boolean>(
    () => selectedTarget.value?.has_hierarchy === true,
);

const filterInputs = computed<HiddenFilterInput[]>(() => {
    if (filter.value.conditions.length === 0) {
        return [];
    }

    const inputs: HiddenFilterInput[] = [];

    collectInputs(filter.value, 'config[filter]', inputs);

    return inputs;
});

function collectInputs(
    node: FilterGroupNode,
    prefix: string,
    inputs: HiddenFilterInput[],
): void {
    inputs.push({ name: `${prefix}[combinator]`, value: node.combinator });

    node.conditions.forEach((child, index) => {
        const childPrefix = `${prefix}[conditions][${index}]`;

        if ('combinator' in child) {
            collectInputs(child, childPrefix, inputs);

            return;
        }

        inputs.push({ name: `${childPrefix}[field]`, value: child.field });
        inputs.push({
            name: `${childPrefix}[operator]`,
            value: child.operator,
        });

        if (Array.isArray(child.value)) {
            child.value.forEach((entry, valueIndex) => {
                inputs.push({
                    name: `${childPrefix}[value][${valueIndex}]`,
                    value: String(entry),
                });
            });
        } else if (child.value !== undefined && child.value !== null) {
            inputs.push({
                name: `${childPrefix}[value]`,
                value: String(child.value),
            });
        }

        if (child.valueTo !== undefined && child.valueTo !== null) {
            inputs.push({
                name: `${childPrefix}[valueTo]`,
                value: String(child.valueTo),
            });
        }
    });
}

watch(relationshipTypeId, () => {
    if (!subtreeAvailable.value) {
        scope.value = 'direct_children';
    }

    filter.value = emptyTree;
});
</script>

<template>
    <div
        v-if="props.fieldType === 'rollup'"
        data-testid="rollup-config"
        :aria-invalid="props.errorMessage ? true : undefined"
        class="grid gap-4"
    >
        <div class="grid gap-2">
            <Label :for="`${controlId}_aggregate`">{{
                t(
                    'i18n.components.engine.object_type.rollup_field_editor.aggregation',
                )
            }}</Label>
            <Select v-model="aggregate" name="config[aggregate]">
                <SelectTrigger :id="`${controlId}_aggregate`" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.object_type.rollup_field_editor.select_an_aggregate',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in rollupAggregateOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="grid gap-2">
            <Label :for="`${controlId}_relationship`">{{
                t(
                    'i18n.components.engine.object_type.rollup_field_editor.relationship',
                )
            }}</Label>
            <Select
                v-model="relationshipTypeId"
                name="config[relationship_type_id]"
            >
                <SelectTrigger :id="`${controlId}_relationship`" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.object_type.rollup_field_editor.select_a_relationship_type',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="target in props.rollupTargets"
                        :key="target.value"
                        :value="target.value"
                    >
                        {{ target.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p
                v-if="props.rollupTargets.length === 0"
                class="text-xs text-muted-foreground"
            >
                {{
                    t(
                        'i18n.components.engine.object_type.rollup_field_editor.this_object_type_has_no_relationship_type_pointing_at',
                    )
                }}
            </p>
        </div>

        <div class="grid gap-2">
            <Label :for="`${controlId}_source_field`">
                {{
                    t(
                        'i18n.components.engine.object_type.rollup_field_editor.aggregated_field_key',
                    )
                }}
            </Label>
            <Input
                :id="`${controlId}_source_field`"
                v-model="sourceFieldKey"
                name="config[source_field_key]"
                :list="`${controlId}_source_field_options`"
                :placeholder="
                    t(
                        'i18n.components.engine.object_type.rollup_field_editor.e_g_amount',
                    )
                "
                autocomplete="off"
                spellcheck="false"
                class="font-mono"
            />
            <datalist :id="`${controlId}_source_field_options`">
                <option
                    v-for="field in targetFields"
                    :key="field.key"
                    :value="field.key"
                >
                    {{ field.label }}
                </option>
            </datalist>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.object_type.rollup_field_editor.the_key_of_the_field_on_the_related_records',
                    )
                }}
            </p>
        </div>

        <div class="grid gap-2">
            <Label :for="`${controlId}_scope`">{{
                t(
                    'i18n.components.engine.object_type.rollup_field_editor.aggregation_scope',
                )
            }}</Label>
            <Select v-model="scope" name="config[scope]">
                <SelectTrigger :id="`${controlId}_scope`" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.object_type.rollup_field_editor.select_a_scope',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in rollupScopeOptions"
                        :key="option.value"
                        :value="option.value"
                        :disabled="
                            option.value === 'subtree' && !subtreeAvailable
                        "
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p
                v-if="selectedTarget === null"
                class="text-xs text-muted-foreground"
            >
                {{
                    t(
                        'i18n.components.engine.object_type.rollup_field_editor.select_a_relationship_type_to_choose_the_aggregation_scope',
                    )
                }}
            </p>
            <p
                v-else-if="!subtreeAvailable"
                class="text-xs text-muted-foreground"
            >
                {{ noHierarchyHint }}
            </p>
        </div>

        <div class="grid gap-2">
            <span class="text-sm leading-none font-medium">
                {{
                    t(
                        'i18n.components.engine.object_type.rollup_field_editor.filter_condition_optional',
                    )
                }}
            </span>
            <FilterBuilder
                :key="relationshipTypeId"
                v-model="filter"
                :fields="targetFields"
                :show-actions="false"
            />
            <input
                v-for="input in filterInputs"
                :key="input.name"
                type="hidden"
                :name="input.name"
                :value="input.value"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.object_type.rollup_field_editor.only_related_records_matching_every_condition_are_aggregated',
                    )
                }}
            </p>
        </div>

        <InputError :message="props.errorMessage" />
    </div>
</template>
