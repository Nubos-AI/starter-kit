<script setup lang="ts">
import { RefreshCw } from '@lucide/vue';
import { useI18n } from '@/shared/composables/useI18n';
import AlertError from '@/shared/ui/AlertError.vue';
import { Button } from '@/shared/ui/primitives/button';

const { t } = useI18n();

withDefaults(
  defineProps<{
    title: string;
    defaultMessage: string;
    message?: string;
    retryLabel?: string;
    retryAttrs?: Record<string, string>;
  }>(),
  {
    message: undefined,
    retryLabel: undefined,
    retryAttrs: undefined,
  },
);

const emit = defineEmits<{
  retry: [];
}>();
</script>

<template>
  <div class="contents">
    <AlertError :title="title" :message="message ?? defaultMessage" />
    <Button variant="outline" class="self-start" v-bind="retryAttrs" @click="emit('retry')">
      <RefreshCw />
      {{ retryLabel ?? t('i18n.components.engine.state.error_state.try_again') }}
    </Button>
  </div>
</template>
