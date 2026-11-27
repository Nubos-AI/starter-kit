<script setup lang="ts">
import { AlertCircle } from '@lucide/vue';
import { computed, useSlots } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import { Alert, AlertDescription, AlertTitle } from '@/shared/ui/primitives/alert';

const { t } = useI18n();

type Props = {
  errors?: string[];
  message?: string | null;
  title?: string;
};

const props = withDefaults(defineProps<Props>(), {
  errors: () => [],
  message: undefined,
  title: undefined,
});

const slots = useSlots();

const uniqueErrors = computed(() => Array.from(new Set(props.errors)));

const isListed = computed(() => (props.message === undefined || props.message === null) && slots.default === undefined);

const resolvedTitle = computed(
  () => props.title ?? (isListed.value ? t('i18n.components.alert_error.something_went_wrong') : undefined),
);
</script>

<template>
  <Alert variant="destructive">
    <AlertCircle class="size-4" />
    <AlertTitle v-if="resolvedTitle">{{ resolvedTitle }}</AlertTitle>
    <AlertDescription>
      <slot>
        <p v-if="!isListed">{{ message }}</p>
        <ul v-else class="list-inside list-disc text-sm">
          <li v-for="(error, index) in uniqueErrors" :key="index">
            {{ error }}
          </li>
        </ul>
      </slot>
    </AlertDescription>
  </Alert>
</template>
