<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import InputError from '@/shared/ui/InputError.vue';
import { Skeleton } from '@/shared/ui/primitives/skeleton';

const { t } = useI18n();

const props = defineProps<{
  count: number | null;
  approximate: boolean;
  loading: boolean;
  error: string | null;
}>();

const message = computed<string>(() => {
  if (props.count === null) {
    return t('i18n.components.engine.rules.rule_preview.no_preview_available_yet');
  }

  const prefix = props.approximate ? t('i18n.components.engine.rules.rule_preview.approx') : '';

  return t('i18n.components.engine.rules.rule_preview.records_affected', {
    value1: prefix,
    value2: props.count,
  });
});
</script>

<template>
  <section
    :aria-label="t('i18n.components.engine.rules.rule_preview.preview')"
    aria-live="polite"
    class="flex flex-col gap-2"
  >
    <span class="text-sm font-medium">{{ t('i18n.components.engine.rules.rule_preview.preview') }}</span>

    <Skeleton v-if="loading" class="h-5 w-40" />
    <InputError v-else-if="error !== null" role="alert" :message="error" />
    <p v-else class="text-sm text-muted-foreground">
      {{ message }}
    </p>
  </section>
</template>
