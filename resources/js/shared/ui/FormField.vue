<script setup lang="ts">
import { Info } from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import InputError from '@/shared/ui/InputError.vue';
import { Label } from '@/shared/ui/primitives/label';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/shared/ui/primitives/tooltip';

const { t } = useI18n();

const props = defineProps<{
  label?: string;
  for?: string;
  error?: string;
  hint?: string;
  hideLabel?: boolean;
  labelId?: string;
  required?: boolean;
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

const helpId = computed<string | undefined>(() => (props.help && props.for ? `${props.for}-help` : undefined));

const hintId = computed<string | undefined>(() => (props.hint && props.for ? `${props.for}-hint` : undefined));

const errorId = computed<string | undefined>(() => (props.error && props.for ? `${props.for}-error` : undefined));

const describedBy = computed<string | undefined>(
  () => [helpId.value, hintId.value, errorId.value].filter(Boolean).join(' ') || undefined,
);
</script>

<template>
  <div class="grid content-start gap-2" data-form-field>
    <div class="flex items-center gap-1.5" :class="{ 'sr-only': props.hideLabel }">
      <Label :id="props.labelId" :for="props.for">
        <slot name="label">{{ props.label }}</slot>
        <span v-if="props.required" class="text-destructive" aria-hidden="true"> * </span>
        <span v-if="props.required" class="sr-only">{{ t('i18n.components.form_field.required') }}</span>
      </Label>

      <TooltipProvider v-if="props.help">
        <Tooltip :open="helpOpen">
          <TooltipTrigger
            :data-field-help-trigger="props.for"
            :aria-label="t('i18n.components.form_field.explanation_of', { value1: props.label ?? '' })"
            :aria-expanded="helpOpen"
            class="text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
            @click="toggleHelp"
            @keydown.escape="closeHelp"
          >
            <Info class="size-3.5" aria-hidden="true" />
          </TooltipTrigger>
          <TooltipContent class="max-w-xs">
            {{ props.help }}
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    </div>
    <slot :described-by="describedBy" />
    <span v-if="props.help" :id="helpId" class="sr-only">{{ props.help }}</span>
    <p v-if="props.hint || $slots.hint" :id="hintId" class="text-xs text-muted-foreground" data-form-field-hint>
      <slot name="hint">{{ props.hint }}</slot>
    </p>
    <InputError v-if="props.error" :id="errorId" :message="props.error" />
  </div>
</template>
