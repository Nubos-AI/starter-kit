<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { useRecordNotes } from '@/composables/useRecordNotes';
import type { RecordNoteItem } from '@/composables/useRecordNotes';

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

const canSubmit = computed<boolean>(
    () => body.value.trim() !== '' && !saving.value,
);

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
    <div
        v-if="!props.readonly"
        data-record-note-composer
        class="flex flex-col gap-2"
    >
        <Label for="record-note-body">{{
            t('i18n.components.engine.notes.record_note_composer.note')
        }}</Label>

        <Textarea
            id="record-note-body"
            v-model="body"
            data-record-note-body
            rows="3"
            :placeholder="
                t(
                    'i18n.components.engine.notes.record_note_composer.what_would_you_like_to_note_about_this_record',
                )
            "
            :disabled="saving"
        />

        <InputError :message="error ?? undefined" />

        <div class="flex justify-end">
            <Button
                type="button"
                data-record-note-submit
                :disabled="!canSubmit"
                @click="submit"
            >
                {{
                    t('i18n.components.engine.notes.record_note_composer.save')
                }}
            </Button>
        </div>
    </div>
</template>
