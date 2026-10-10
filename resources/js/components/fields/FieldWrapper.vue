<script setup lang="ts">
import { Info } from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = defineProps<{
    fieldId: string;
    label: string;
    required?: boolean;
    error?: string;
    help?: string | null;
}>();

const HELP_VISIBLE_MS = 3000;

const helpOpen = ref<boolean>(false);

const helpTimer = ref<ReturnType<typeof setTimeout> | null>(null);

function closeHelp(): void {
    if (helpTimer.value !== null) {
        clearTimeout(helpTimer.value);
        helpTimer.value = null;
    }

    helpOpen.value = false;
}

function toggleHelp(): void {
    if (helpOpen.value) {
        closeHelp();

        return;
    }

    helpOpen.value = true;
    helpTimer.value = setTimeout(closeHelp, HELP_VISIBLE_MS);
}

onUnmounted(closeHelp);

const helpId = computed(() =>
    props.help ? `${props.fieldId}-help` : undefined,
);

const errorId = computed(() =>
    props.error ? `${props.fieldId}-error` : undefined,
);

const describedBy = computed(
    () => [helpId.value, errorId.value].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center gap-1.5">
            <Label :for="fieldId">
                {{ label }}
                <span
                    v-if="required"
                    class="text-destructive"
                    aria-hidden="true"
                >
                    *
                </span>
                <span v-if="required" class="sr-only">{{
                    t('i18n.components.fields.field_wrapper.required')
                }}</span>
            </Label>

            <TooltipProvider v-if="help">
                <Tooltip :open="helpOpen">
                    <TooltipTrigger
                        :data-field-help-trigger="fieldId"
                        :aria-label="
                            t(
                                'i18n.components.fields.field_wrapper.explanation_of',
                                { value1: label },
                            )
                        "
                        :aria-expanded="helpOpen"
                        class="text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        @click="toggleHelp"
                        @keydown.escape="closeHelp"
                    >
                        <Info class="size-3.5" aria-hidden="true" />
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        {{ help }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>

        <slot :described-by="describedBy" />

        <span v-if="help" :id="helpId" class="sr-only">{{ help }}</span>

        <InputError v-if="error" :id="errorId" :message="error" />
    </div>
</template>
