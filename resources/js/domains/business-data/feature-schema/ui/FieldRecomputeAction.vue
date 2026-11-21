<script setup lang="ts">
import { ref } from 'vue';
import { store as recomputeField } from '@/actions/App/Http/Controllers/Engine/FieldRecomputesController';
import { useI18n } from '@/shared/composables/useI18n';
import { buildHeaders } from '@/shared/composables/useRequestHeaders';
import { Button } from '@/shared/ui/primitives/button';
import SubsectionHeading from '@/shared/ui/SubsectionHeading.vue';

const { t, n } = useI18n();

const props = defineProps<{
  objectTypeSlug: string;
  fieldId: string;
}>();

const START_FAILED = t('i18n.components.engine.object_type.field_recompute_action.recalculation_could_not_be_started');

const isStarting = ref<boolean>(false);
const statusMessage = ref<string>('');
const errorMessage = ref<string>('');

interface RecomputeResponse {
  run: { totalCount: number } | null;
}

function startedMessage(payload: RecomputeResponse): string {
  return payload.run === null
    ? t('i18n.components.engine.object_type.field_recompute_action.recalculation_is_running')
    : t('i18n.components.engine.object_type.field_recompute_action.recalculation_in_progress_records', {
        value1: n(payload.run.totalCount),
      });
}

async function start(): Promise<void> {
  if (isStarting.value) {
    return;
  }

  isStarting.value = true;
  statusMessage.value = '';
  errorMessage.value = '';

  try {
    const response = await fetch(
      recomputeField.url({
        objectType: props.objectTypeSlug,
        field: props.fieldId,
      }),
      {
        method: 'POST',
        credentials: 'same-origin',
        headers: buildHeaders(),
      },
    );

    if (!response.ok) {
      errorMessage.value = START_FAILED;

      return;
    }

    statusMessage.value = startedMessage((await response.json()) as RecomputeResponse);
  } catch {
    errorMessage.value = START_FAILED;
  } finally {
    isStarting.value = false;
  }
}
</script>

<template>
  <div data-field-recompute class="grid gap-2 border-t pt-4">
    <SubsectionHeading
      :title="t('i18n.components.engine.object_type.field_recompute_action.recalculate_existing_records')"
      :description="
        t(
          'i18n.components.engine.object_type.field_recompute_action.newly_saved_records_are_calculated_immediately_start_the_calculation',
        )
      "
    />
    <div class="flex items-center gap-3">
      <Button type="button" variant="outline" size="sm" :disabled="isStarting" @click="start">
        {{ t('i18n.components.engine.object_type.field_recompute_action.recalculate') }}
      </Button>
      <span v-if="statusMessage" role="status" aria-live="polite" class="text-xs text-muted-foreground">
        {{ statusMessage }}
      </span>
      <span v-if="errorMessage" class="text-xs text-destructive">
        {{ errorMessage }}
      </span>
    </div>
  </div>
</template>
