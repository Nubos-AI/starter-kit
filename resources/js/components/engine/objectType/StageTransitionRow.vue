<script setup lang="ts">
import { ArrowRight, Pencil } from '@lucide/vue';
import { computed } from 'vue';
import {
    DeleteButton,
    IconActionButton,
} from '@/components/ui/icon-action-button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const CONDITION_PREFIX = t(
    'i18n.components.engine.object_type.stage_transition_row.only_if',
);

const CONDITION_GLUE = t(
    'i18n.components.engine.object_type.stage_transition_row.and',
);

const NO_CONDITION = t(
    'i18n.components.engine.object_type.stage_transition_row.no_condition',
);

const props = defineProps<{
    targetLabel: string;
    transitionId: string | null;
    gateSummary: string[];
    gateHref: string | null;
    gateLabel: string;
    gateLockedReason: string | null;
    removable: boolean;
    removeLabel: string;
    removeLockedReason: string | null;
}>();

const emit = defineEmits<{
    remove: [];
}>();

const conditionText = computed<string>(() =>
    props.gateSummary.length === 0
        ? NO_CONDITION
        : CONDITION_PREFIX + props.gateSummary.join(CONDITION_GLUE),
);
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-md border px-2 py-1.5"
    >
        <ArrowRight class="size-4 icon-subtlest" />
        <span class="font-medium">{{ props.targetLabel }}</span>

        <slot />

        <span
            v-if="props.transitionId !== null"
            data-gate-summary
            class="ml-auto text-xs text-muted-foreground"
        >
            {{ conditionText }}
        </span>

        <div
            class="flex items-center gap-1"
            :class="props.transitionId === null ? 'ml-auto' : ''"
        >
            <IconActionButton
                v-if="props.transitionId !== null && props.gateHref !== null"
                :icon="Pencil"
                variant="edit"
                :label="props.gateLabel"
                :href="props.gateHref"
                :test-id="`transition-gate-link-${props.transitionId}`"
            />

            <TooltipProvider
                v-else-if="props.transitionId !== null"
                :delay-duration="0"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span>
                            <IconActionButton
                                :icon="Pencil"
                                variant="edit"
                                :label="props.gateLabel"
                                disabled
                                :test-id="`transition-gate-link-${props.transitionId}`"
                            />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent
                        :data-testid="`transition-gate-reason-${props.transitionId}`"
                    >
                        {{ props.gateLockedReason }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <DeleteButton
                v-if="props.removable && props.removeLockedReason === null"
                data-transition-remove
                :label="props.removeLabel"
                @click="emit('remove')"
            />

            <TooltipProvider v-else-if="props.removable" :delay-duration="0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span>
                            <DeleteButton
                                data-transition-remove
                                :label="props.removeLabel"
                                disabled
                            />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent data-transition-remove-reason>
                        {{ props.removeLockedReason }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
    </div>
</template>
