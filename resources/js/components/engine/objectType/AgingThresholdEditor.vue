<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { AGING_THRESHOLD_COLOR } from '@/lib/statusMaps';
import type { AgingThreshold } from '@/types/aging';
import { AGING_THRESHOLD_COLORS, hasDuplicateDurations } from '@/types/aging';

const { t } = useI18n();

const DUPLICATE_MESSAGE = t(
    'i18n.components.engine.object_type.aging_threshold_editor.two_levels_cannot_have_the_same_duration',
);

const DURATION_LABEL = t(
    'i18n.components.engine.object_type.aging_threshold_editor.duration_in_days',
);

const COLOR_LABEL = t(
    'i18n.components.engine.object_type.aging_threshold_editor.level',
);

const props = withDefaults(
    defineProps<{
        errors?: Record<string, string>;
    }>(),
    { errors: () => ({}) },
);

const thresholds = defineModel<AgingThreshold[]>({ required: true });

const controlId = useId();

const colorOptions = AGING_THRESHOLD_COLORS.map((color) => ({
    value: color,
    label: AGING_THRESHOLD_COLOR[color].label,
}));

const hasDuplicates = computed<boolean>(() =>
    hasDuplicateDurations(thresholds.value),
);

function durationId(index: number): string {
    return `${controlId}-duration-${index}`;
}

function colorId(index: number): string {
    return `${controlId}-color-${index}`;
}

function replace(index: number, threshold: AgingThreshold): void {
    const next = [...thresholds.value];

    next[index] = threshold;
    thresholds.value = next;
}

function setDuration(index: number, value: string | number): void {
    const days = Number(value);

    replace(index, {
        ...thresholds.value[index],
        after_days: Number.isFinite(days) ? days : 0,
    });
}

function setColor(index: number, value: unknown): void {
    const color = AGING_THRESHOLD_COLORS.find(
        (entry) => entry === String(value),
    );

    if (color === undefined) {
        return;
    }

    replace(index, { ...thresholds.value[index], color });
}

function addThreshold(): void {
    const last = thresholds.value[thresholds.value.length - 1];

    thresholds.value = [
        ...thresholds.value,
        {
            after_days: last === undefined ? 1 : last.after_days + 1,
            color: last?.color ?? 'amber',
        },
    ];
}

function removeThreshold(index: number): void {
    thresholds.value = thresholds.value.filter(
        (threshold, position) => position !== index,
    );
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            class="grid grid-cols-[8rem_1fr_auto] gap-2 text-xs text-muted-foreground"
            aria-hidden="true"
        >
            <span>{{ DURATION_LABEL }}</span>
            <span>{{ COLOR_LABEL }}</span>
            <span />
        </div>

        <div
            v-for="(threshold, index) in thresholds"
            :key="index"
            class="grid grid-cols-[8rem_1fr_auto] items-start gap-2"
            data-aging-threshold-row
        >
            <div class="grid gap-1">
                <Label :for="durationId(index)" class="sr-only">
                    {{ DURATION_LABEL }}
                </Label>
                <Input
                    :id="durationId(index)"
                    type="number"
                    min="1"
                    :model-value="threshold.after_days"
                    @update:model-value="(value) => setDuration(index, value)"
                />
                <InputError
                    :message="props.errors[`thresholds.${index}.after_days`]"
                />
            </div>

            <div class="grid gap-1">
                <Label :for="colorId(index)" class="sr-only">
                    {{ COLOR_LABEL }}
                </Label>
                <Select
                    :model-value="threshold.color"
                    :name="`thresholds_${index}_color`"
                    @update:model-value="(value) => setColor(index, value)"
                >
                    <SelectTrigger :id="colorId(index)" class="w-full">
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.components.engine.object_type.aging_threshold_editor.select_level',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in colorOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError
                    :message="props.errors[`thresholds.${index}.color`]"
                />
            </div>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="thresholds.length === 1"
                :aria-label="
                    t(
                        'i18n.components.engine.object_type.aging_threshold_editor.remove_stage',
                        { value1: index + 1 },
                    )
                "
                data-aging-threshold-remove
                @click="removeThreshold(index)"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>

        <p
            v-if="hasDuplicates"
            class="text-sm text-destructive"
            data-aging-threshold-duplicate
        >
            {{ DUPLICATE_MESSAGE }}
        </p>

        <div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-aging-threshold-add
                @click="addThreshold"
            >
                <Plus class="size-4" />
                {{
                    t(
                        'i18n.components.engine.object_type.aging_threshold_editor.add_level',
                    )
                }}
            </Button>
        </div>
    </div>
</template>
