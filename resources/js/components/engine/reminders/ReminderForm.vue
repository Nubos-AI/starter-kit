<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { useReminders } from '@/composables/useReminders';
import type { ReminderInput, ReminderItem } from '@/composables/useReminders';

const { t } = useI18n();

interface TypeOption {
    value: string;
    label: string;
}

const NO_TYPE = '__none__';

const page = usePage<{ reminderTypeOptions?: TypeOption[] }>();

const reminderTypeOptions = computed<TypeOption[]>(
    () => page.props.reminderTypeOptions ?? [],
);

const props = defineProps<{
    open: boolean;
    recordId?: string | null;
    reminder?: ReminderItem | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [];
}>();

const { create, update } = useReminders();

const subject = ref<string>('');
const dueAt = ref<string>('');
const note = ref<string>('');
const reminderTypeId = ref<string>(NO_TYPE);
const submitting = ref<boolean>(false);
const errorMessage = ref<string | null>(null);

const isEditing = computed<boolean>(() => props.reminder != null);

const heading = computed<string>(() =>
    isEditing.value
        ? t('i18n.components.engine.reminders.reminder_form.edit_reminder')
        : t('i18n.components.engine.reminders.reminder_form.new_reminder'),
);

const subjectInvalid = computed<boolean>(() => subject.value.trim() === '');

const submitDisabled = computed<boolean>(
    () => submitting.value || subjectInvalid.value,
);

function toInputDate(value: string | null): string {
    if (value === null) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const localDate = new Date(
        date.getTime() - date.getTimezoneOffset() * 60000,
    );

    return localDate.toISOString().slice(0, 16);
}

function resetForm(): void {
    subject.value = props.reminder?.subject ?? '';
    dueAt.value = toInputDate(props.reminder?.dueAt ?? null);
    note.value = props.reminder?.note ?? '';
    reminderTypeId.value = props.reminder?.type?.id ?? NO_TYPE;
    errorMessage.value = null;
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            resetForm();
        }
    },
    { immediate: true },
);

function buildInput(): ReminderInput {
    const input: ReminderInput = {
        subject: subject.value.trim(),
        due_at: dueAt.value === '' ? null : new Date(dueAt.value).toISOString(),
        note: note.value.trim() === '' ? null : note.value.trim(),
        reminder_type_id:
            reminderTypeId.value === NO_TYPE ? null : reminderTypeId.value,
    };

    if (!isEditing.value && props.recordId != null && props.recordId !== '') {
        input.record_id = props.recordId;
    }

    return input;
}

async function submit(): Promise<void> {
    if (submitDisabled.value) {
        return;
    }

    submitting.value = true;
    errorMessage.value = null;

    try {
        const input = buildInput();
        const saved =
            props.reminder != null
                ? await update(props.reminder.id, input)
                : await create(input);

        if (saved === null) {
            errorMessage.value = t(
                'i18n.components.engine.reminders.reminder_form.the_reminder_could_not_be_saved_please_try_again',
            );

            return;
        }

        emit('saved');
        emit('update:open', false);
    } finally {
        submitting.value = false;
    }
}

function onOpenChange(value: boolean): void {
    if (!value) {
        emit('update:open', false);
    }
}
</script>

<template>
    <Sheet :open="open" @update:open="onOpenChange">
        <SheetContent side="right" class="w-full gap-0 sm:max-w-md">
            <form class="flex h-full min-h-0 flex-col" @submit.prevent="submit">
                <SheetHeader class="border-b">
                    <SheetTitle>{{ heading }}</SheetTitle>
                    <SheetDescription>
                        {{
                            t(
                                'i18n.components.engine.reminders.reminder_form.set_the_due_date_subject_and_note_for_this',
                            )
                        }}
                    </SheetDescription>
                </SheetHeader>

                <div
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4"
                >
                    <div class="flex flex-col gap-2">
                        <Label for="reminder-subject">{{
                            t(
                                'i18n.components.engine.reminders.reminder_form.subject',
                            )
                        }}</Label>
                        <Input
                            id="reminder-subject"
                            v-model="subject"
                            data-reminder-subject
                            required
                            :aria-invalid="subjectInvalid ? true : undefined"
                            :placeholder="
                                t(
                                    'i18n.components.engine.reminders.reminder_form.what_is_this_about',
                                )
                            "
                        />
                        <p
                            v-if="subjectInvalid"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'i18n.components.engine.reminders.reminder_form.a_subject_is_required',
                                )
                            }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="reminder-due">{{
                            t(
                                'i18n.components.engine.reminders.reminder_form.due_date',
                            )
                        }}</Label>
                        <Input
                            id="reminder-due"
                            v-model="dueAt"
                            type="datetime-local"
                            data-reminder-due
                        />
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="reminder-type">{{
                            t(
                                'i18n.components.engine.reminders.reminder_form.type',
                            )
                        }}</Label>
                        <Select v-model="reminderTypeId">
                            <SelectTrigger
                                id="reminder-type"
                                data-reminder-type
                            >
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.components.engine.reminders.reminder_form.no_type',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem :value="NO_TYPE">
                                        {{
                                            t(
                                                'i18n.components.engine.reminders.reminder_form.no_type',
                                            )
                                        }}
                                    </SelectItem>
                                    <SelectItem
                                        v-for="option in reminderTypeOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        v-if="isEditing && reminder?.record"
                        class="flex flex-col gap-1"
                    >
                        <Label>{{
                            t(
                                'i18n.components.engine.reminders.reminder_form.record',
                            )
                        }}</Label>
                        <Link
                            :href="
                                RecordsController.show.url({
                                    record: reminder.record.id,
                                })
                            "
                            class="text-sm font-medium text-primary underline-offset-4 hover:underline"
                            data-reminder-record-link
                        >
                            {{
                                reminder.record.label ??
                                t(
                                    'i18n.components.engine.reminders.reminder_form.open_record',
                                )
                            }}
                        </Link>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label for="reminder-note">{{
                            t(
                                'i18n.components.engine.reminders.reminder_form.note',
                            )
                        }}</Label>
                        <Textarea
                            id="reminder-note"
                            v-model="note"
                            data-reminder-note
                            rows="3"
                            :placeholder="
                                t(
                                    'i18n.components.engine.reminders.reminder_form.additional_details_optional',
                                )
                            "
                        />
                    </div>

                    <p
                        v-if="errorMessage !== null"
                        data-reminder-form-error
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errorMessage }}
                    </p>
                </div>

                <SheetFooter class="flex-row items-center gap-2 border-t">
                    <Button
                        type="submit"
                        data-reminder-save
                        :disabled="submitDisabled"
                    >
                        {{
                            submitting
                                ? t(
                                      'i18n.components.engine.reminders.reminder_form.saving',
                                  )
                                : t(
                                      'i18n.components.engine.reminders.reminder_form.save',
                                  )
                        }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="onOpenChange(false)"
                    >
                        {{
                            t(
                                'i18n.components.engine.reminders.reminder_form.cancel',
                            )
                        }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
