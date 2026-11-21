<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@/shared/composables/useClipboard';
import { useI18n } from '@/shared/composables/useI18n';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import { Textarea } from '@/shared/ui/primitives/textarea';

const props = withDefaults(
  defineProps<{
    value: string;
    id?: string;
    multiline?: boolean;
    copyLabel?: string;
    copiedMessage?: string | false;
    failedMessage?: string;
  }>(),
  {
    id: undefined,
    multiline: false,
    copyLabel: undefined,
    copiedMessage: undefined,
    failedMessage: undefined,
  },
);

const { t } = useI18n();
const { copied, copy } = useClipboard({ copied: props.copiedMessage, failed: props.failedMessage });
</script>

<template>
  <div
    :class="props.multiline ? 'flex flex-col gap-2' : 'flex flex-col gap-2 sm:flex-row sm:items-center'"
    data-copy-field
  >
    <Textarea
      v-if="props.multiline"
      :id="props.id"
      :model-value="props.value"
      class="font-mono text-xs"
      rows="3"
      readonly
      data-copy-field-value
    />
    <Input v-else :id="props.id" :model-value="props.value" readonly data-copy-field-value />
    <div :class="props.multiline ? 'flex items-center gap-2 self-end' : 'flex shrink-0 items-center gap-2'">
      <Button type="button" variant="outline" data-copy-field-copy @click="copy(props.value)">
        <Check v-if="copied" aria-hidden="true" />
        <Copy v-else aria-hidden="true" />
        {{ props.copyLabel ?? t('i18n.components.copy_field.copy') }}
      </Button>
      <slot />
    </div>
  </div>
</template>
