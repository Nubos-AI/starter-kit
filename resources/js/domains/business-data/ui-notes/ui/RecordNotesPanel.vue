<script setup lang="ts">
import { useI18n } from '@/shared/composables/useI18n';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import RecordNoteComposer from './RecordNoteComposer.vue';

const { t } = useI18n();

const CARD_TITLE = t('i18n.components.engine.notes.record_notes_panel.notes');

const CARD_DESCRIPTION = t(
  'i18n.components.engine.notes.record_notes_panel.add_a_note_about_this_record_to_the_timeline',
);

const READONLY_HINT = t('i18n.components.engine.notes.record_notes_panel.notes_cannot_be_added_to_a_deleted_record');

const props = withDefaults(
  defineProps<{
    recordId: string;
    readonly?: boolean;
  }>(),
  { readonly: false },
);

const emit = defineEmits<{ changed: [] }>();
</script>

<template>
  <Card data-record-notes-panel>
    <CardHeader>
      <CardTitle>{{ CARD_TITLE }}</CardTitle>
      <CardDescription>{{ CARD_DESCRIPTION }}</CardDescription>
    </CardHeader>

    <CardContent>
      <RecordNoteComposer :record-id="props.recordId" :readonly="props.readonly" @created="emit('changed')" />

      <p v-if="props.readonly" data-record-notes-readonly class="text-sm text-muted-foreground">
        {{ READONLY_HINT }}
      </p>
    </CardContent>
  </Card>
</template>
