<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useModuleOptions } from '@/composables/useModuleOptions';
import {
    addLeadStage,
    availableWatchFields,
    removeLeadStage,
} from '@/composables/useRules';
import type {
    DateBasedConfig,
    FieldChangeConfig,
    LeadStage,
    LeadStageUnit,
    RuleConfig,
    RuleTriggerType,
} from '@/composables/useRules';
import type { FieldDefinition } from '@/types/fields';

const { t } = useI18n();

export interface TriggerState {
    triggerType: RuleTriggerType;
    config: RuleConfig;
}

const props = defineProps<{
    fields: FieldDefinition[];
    modelValue: TriggerState;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: TriggerState];
}>();

const triggerOptions = useModuleOptions('notifications.triggers', [
    {
        value: 'date_based',
        label: t('i18n.components.engine.rules.rule_trigger_config.date'),
    },
    {
        value: 'assignment',
        label: t('i18n.components.engine.rules.rule_trigger_config.assignment'),
    },
    {
        value: 'field_change',
        label: t(
            'i18n.components.engine.rules.rule_trigger_config.field_change',
        ),
    },
    {
        value: 'creation',
        label: t('i18n.components.engine.rules.rule_trigger_config.creation'),
    },
]);

const unitOptions: Array<{ value: LeadStageUnit; label: string }> = [
    {
        value: 'hour',
        label: t('i18n.components.engine.rules.rule_trigger_config.hours'),
    },
    {
        value: 'day',
        label: t('i18n.components.engine.rules.rule_trigger_config.days'),
    },
    {
        value: 'week',
        label: t('i18n.components.engine.rules.rule_trigger_config.weeks'),
    },
    {
        value: 'month',
        label: t('i18n.components.engine.rules.rule_trigger_config.months'),
    },
];

const dateFields = computed<FieldDefinition[]>(() =>
    props.fields.filter(
        (field) =>
            field.field_type === 'date' || field.field_type === 'datetime',
    ),
);

const watchOptions = computed(() => availableWatchFields(props.fields));

const dateConfig = computed<DateBasedConfig | null>(() =>
    props.modelValue.triggerType === 'date_based'
        ? (props.modelValue.config as DateBasedConfig | null)
        : null,
);

const fieldChangeConfig = computed<FieldChangeConfig | null>(() =>
    props.modelValue.triggerType === 'field_change'
        ? (props.modelValue.config as FieldChangeConfig | null)
        : null,
);

function defaultConfigFor(triggerType: RuleTriggerType): RuleConfig {
    if (triggerType === 'date_based') {
        return { date_field_key: '', lead_stages: [] };
    }

    if (triggerType === 'field_change') {
        return { watched_field_keys: [] };
    }

    return null;
}

function onTriggerChange(value: unknown): void {
    const triggerType = value as RuleTriggerType;

    emit('update:modelValue', {
        triggerType,
        config: defaultConfigFor(triggerType),
    });
}

function updateDateConfig(patch: Partial<DateBasedConfig>): void {
    const current = dateConfig.value ?? {
        date_field_key: '',
        lead_stages: [],
    };

    emit('update:modelValue', {
        triggerType: 'date_based',
        config: { ...current, ...patch },
    });
}

function onDateFieldChange(value: unknown): void {
    updateDateConfig({ date_field_key: String(value) });
}

function onAddLeadStage(): void {
    const current = dateConfig.value?.lead_stages ?? [];

    updateDateConfig({ lead_stages: addLeadStage(current) });
}

function onRemoveLeadStage(index: number): void {
    const current = dateConfig.value?.lead_stages ?? [];

    updateDateConfig({ lead_stages: removeLeadStage(current, index) });
}

function onLeadStageValue(index: number, raw: string): void {
    const current = dateConfig.value?.lead_stages ?? [];
    const next = current.map((stage, position) =>
        position === index
            ? {
                  ...stage,
                  value: Math.max(0, Number.parseInt(raw, 10) || 0),
              }
            : stage,
    );

    updateDateConfig({ lead_stages: next });
}

function onLeadStageUnit(index: number, unit: unknown): void {
    const current = dateConfig.value?.lead_stages ?? [];
    const next: LeadStage[] = current.map((stage, position) =>
        position === index ? { ...stage, unit: unit as LeadStageUnit } : stage,
    );

    updateDateConfig({ lead_stages: next });
}

function isWatched(key: string): boolean {
    return fieldChangeConfig.value?.watched_field_keys.includes(key) ?? false;
}

function toggleWatched(key: string, checked: boolean | 'indeterminate'): void {
    const current = fieldChangeConfig.value?.watched_field_keys ?? [];
    const next =
        checked === true
            ? [...new Set([...current, key])]
            : current.filter((entry) => entry !== key);

    emit('update:modelValue', {
        triggerType: 'field_change',
        config: { watched_field_keys: next },
    });
}
</script>

<template>
    <section
        :aria-label="
            t('i18n.components.engine.rules.rule_trigger_config.trigger')
        "
        class="flex flex-col gap-4"
    >
        <div class="grid gap-2">
            <Label for="rule-trigger-type">{{
                t('i18n.components.engine.rules.rule_trigger_config.trigger')
            }}</Label>
            <Select
                :model-value="modelValue.triggerType"
                @update:model-value="onTriggerChange"
            >
                <SelectTrigger id="rule-trigger-type" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.engine.rules.rule_trigger_config.select_trigger',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in triggerOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div
            v-if="modelValue.triggerType === 'date_based'"
            class="flex flex-col gap-3"
        >
            <div class="grid gap-2">
                <Label for="rule-date-field">{{
                    t(
                        'i18n.components.engine.rules.rule_trigger_config.date_field',
                    )
                }}</Label>
                <Select
                    :model-value="dateConfig?.date_field_key ?? undefined"
                    @update:model-value="onDateFieldChange"
                >
                    <SelectTrigger id="rule-date-field" class="w-full">
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.components.engine.rules.rule_trigger_config.select_date_field',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="field in dateFields"
                            :key="field.key"
                            :value="field.key"
                        >
                            {{ field.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">{{
                    t(
                        'i18n.components.engine.rules.rule_trigger_config.lead_times',
                    )
                }}</span>
                <p
                    v-if="(dateConfig?.lead_stages.length ?? 0) === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'i18n.components.engine.rules.rule_trigger_config.no_lead_times_yet',
                        )
                    }}
                </p>
                <div
                    v-for="(stage, index) in dateConfig?.lead_stages ?? []"
                    :key="index"
                    class="flex items-end gap-2"
                >
                    <div class="grid gap-1">
                        <Label :for="`lead-value-${index}`">{{
                            t(
                                'i18n.components.engine.rules.rule_trigger_config.value',
                            )
                        }}</Label>
                        <Input
                            :id="`lead-value-${index}`"
                            type="number"
                            min="0"
                            class="w-24"
                            :model-value="stage.value"
                            @update:model-value="
                                onLeadStageValue(index, String($event))
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label :for="`lead-unit-${index}`">{{
                            t(
                                'i18n.components.engine.rules.rule_trigger_config.unit',
                            )
                        }}</Label>
                        <Select
                            :model-value="stage.unit"
                            @update:model-value="onLeadStageUnit(index, $event)"
                        >
                            <SelectTrigger
                                :id="`lead-unit-${index}`"
                                class="w-32"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="unit in unitOptions"
                                    :key="unit.value"
                                    :value="unit.value"
                                >
                                    {{ unit.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-label="
                            t(
                                'i18n.components.engine.rules.rule_trigger_config.remove_lead_time_level',
                                { value1: index + 1 },
                            )
                        "
                        @click="onRemoveLeadStage(index)"
                    >
                        {{
                            t(
                                'i18n.components.engine.rules.rule_trigger_config.remove',
                            )
                        }}
                    </Button>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="w-fit"
                    @click="onAddLeadStage"
                >
                    {{
                        t(
                            'i18n.components.engine.rules.rule_trigger_config.add_lead_time',
                        )
                    }}
                </Button>
            </div>
        </div>

        <fieldset
            v-else-if="modelValue.triggerType === 'field_change'"
            class="flex flex-col gap-2"
        >
            <legend class="text-sm font-medium">
                {{
                    t(
                        'i18n.components.engine.rules.rule_trigger_config.watched_fields',
                    )
                }}
            </legend>
            <div
                v-for="option in watchOptions"
                :key="option.key"
                class="flex items-center gap-2"
            >
                <Checkbox
                    :id="`watch-${option.key}`"
                    :disabled="option.disabled"
                    :model-value="isWatched(option.key)"
                    @update:model-value="toggleWatched(option.key, $event)"
                />
                <Label
                    :for="`watch-${option.key}`"
                    :class="option.disabled ? 'text-muted-foreground' : ''"
                >
                    {{ option.label }}
                </Label>
                <Badge v-if="option.disabled" variant="outline">
                    {{
                        t(
                            'i18n.components.engine.rules.rule_trigger_config.encrypted',
                        )
                    }}
                </Badge>
            </div>
        </fieldset>
    </section>
</template>
