<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import { Textarea } from '@/shared/ui/primitives/textarea';
import type { RecordNoteItem } from '../data/useRecordNotes';
import { useRecordNotes } from '../data/useRecordNotes';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    recordId: string;
    readonly?: boolean;
  }>(),
  { readonly: false },
);

const emit = defineEmits<{ created: [note: RecordNoteItem] }>();

const { saving, error, create } = useRecordNotes();

const body = ref<string>('');

const canSubmit = computed<boolean>(() => body.value.trim() !== '' && !saving.value);

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return;
  }

  const note = await create(props.recordId, body.value);

  if (note === null) {
    return;
  }

  body.value = '';
  emit('created', note);
}
</script>

<template>
  <FormField
    v-if="!props.readonly"
    data-record-note-composer
    for="record-note-body"
    :label="t('i18n.components.engine.notes.record_note_composer.note')"
    :error="error ?? undefined"
  >
    <Textarea
      id="record-note-body"
      v-model="body"
      data-record-note-body
      rows="3"
      :placeholder="
        t('i18n.components.engine.notes.record_note_composer.what_would_you_like_to_note_about_this_record')
      "
      :disabled="saving"
    />
    <FormActions type="button" :dirty="body.trim() !== ''" :processing="saving" :cancellable="false" @save="submit" />
  </FormField>
</template>
