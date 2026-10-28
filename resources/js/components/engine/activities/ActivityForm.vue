<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import RecordActivitiesController from '@/actions/App/Http/Controllers/Engine/RecordActivitiesController';
import InputError from '@/components/InputError.vue';
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
    SheetHeader,
    SheetTitle,
    SheetDescription,
    SheetFooter,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type { ActivityOption, RecordActivityItem } from '@/types/activities';

const { t } = useI18n();

const props = defineProps<{
    recordId: string;
    activity: RecordActivityItem | null;
    types: ActivityOption[];
    assignees: ActivityOption[];
}>();
const emit = defineEmits<{ saved: []; cancel: [] }>();
const page = usePage();
const error = ref('');
function localDate(value: string): string {
    const date = new Date(value);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
}
const form = useHttp({
    subject: props.activity?.subject ?? '',
    activity_type_id:
        props.activity?.activityTypeId ?? props.types[0]?.id ?? '',
    occurred_at: localDate(
        props.activity?.occurredAt ?? new Date().toISOString(),
    ),
    assignee_id:
        props.activity?.assigneeId ?? String(page.props.auth?.user?.id ?? ''),
    result: props.activity?.result ?? '',
});
async function save(): Promise<void> {
    error.value = '';
    form.transform((data) => ({
        ...data,
        occurred_at: new Date(data.occurred_at).toISOString(),
    }));

    try {
        if (props.activity) {
            await form.put(
                RecordActivitiesController.update.url({
                    record: props.recordId,
                    activity: props.activity.id,
                }),
            );
        } else {
            await form.post(
                RecordActivitiesController.store.url({
                    record: props.recordId,
                }),
            );
        }

        emit('saved');
    } catch {
        if (!form.hasErrors) {
            error.value = t(
                'i18n.components.engine.activities.activity_form.the_activity_could_not_be_saved_please_try_again',
            );
        }
    }
}
</script>

<template>
    <form
        class="flex h-full min-h-0 flex-col"
        data-activity-form
        @submit.prevent="save"
    >
        <SheetHeader class="border-b">
            <SheetTitle>{{
                activity
                    ? t(
                          'i18n.components.engine.activities.activity_form.edit_activity',
                      )
                    : t(
                          'i18n.components.engine.activities.activity_form.new_activity',
                      )
            }}</SheetTitle>
            <SheetDescription>{{
                t(
                    'i18n.components.engine.activities.activity_form.enter_the_type_time_and_outcome_of_this_activity',
                )
            }}</SheetDescription>
        </SheetHeader>
        <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <Label for="activity-subject">{{
                        t(
                            'i18n.components.engine.activities.activity_form.title',
                        )
                    }}</Label>
                    <Input
                        id="activity-subject"
                        v-model="form.subject"
                        required
                        maxlength="255"
                        :aria-invalid="!!form.errors.subject"
                        autofocus
                    />
                    <InputError :message="form.errors.subject" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="activity-type">{{
                        t(
                            'i18n.components.engine.activities.activity_form.activity_type',
                        )
                    }}</Label>
                    <Select v-model="form.activity_type_id" required>
                        <SelectTrigger
                            id="activity-type"
                            :aria-invalid="!!form.errors.activity_type_id"
                            ><SelectValue
                                :placeholder="
                                    t(
                                        'i18n.components.engine.activities.activity_form.select_type',
                                    )
                                "
                        /></SelectTrigger>
                        <SelectContent
                            ><SelectGroup>
                                <SelectItem
                                    v-if="
                                        activity?.activityTypeId &&
                                        !types.some(
                                            (type) =>
                                                type.id ===
                                                activity?.activityTypeId,
                                        )
                                    "
                                    :value="activity.activityTypeId"
                                    >{{ activity.typeName }}
                                    {{
                                        t(
                                            'i18n.components.engine.activities.activity_form.deleted',
                                        )
                                    }}</SelectItem
                                >
                                <SelectItem
                                    v-for="type in types"
                                    :key="type.id"
                                    :value="type.id"
                                    >{{ type.name }}</SelectItem
                                >
                            </SelectGroup></SelectContent
                        >
                    </Select>
                    <InputError :message="form.errors.activity_type_id" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="activity-occurred-at">{{
                        t(
                            'i18n.components.engine.activities.activity_form.time',
                        )
                    }}</Label>
                    <Input
                        id="activity-occurred-at"
                        v-model="form.occurred_at"
                        type="datetime-local"
                        required
                        :aria-invalid="!!form.errors.occurred_at"
                    />
                    <InputError :message="form.errors.occurred_at" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="activity-assignee">{{
                        t(
                            'i18n.components.engine.activities.activity_form.responsible_person',
                        )
                    }}</Label>
                    <Select v-model="form.assignee_id" required>
                        <SelectTrigger
                            id="activity-assignee"
                            :aria-invalid="!!form.errors.assignee_id"
                            ><SelectValue
                                :placeholder="
                                    t(
                                        'i18n.components.engine.activities.activity_form.select_person',
                                    )
                                "
                        /></SelectTrigger>
                        <SelectContent
                            ><SelectGroup>
                                <SelectItem
                                    v-for="user in assignees"
                                    :key="user.id"
                                    :value="user.id"
                                    >{{ user.name }}</SelectItem
                                >
                            </SelectGroup></SelectContent
                        >
                    </Select>
                    <InputError :message="form.errors.assignee_id" />
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <Label for="activity-result">{{
                    t(
                        'i18n.components.engine.activities.activity_form.outcome_summary',
                    )
                }}</Label>
                <Textarea
                    id="activity-result"
                    v-model="form.result"
                    :rows="3"
                    maxlength="20000"
                    :aria-invalid="!!form.errors.result"
                />
                <InputError :message="form.errors.result" />
            </div>
            <p v-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
            </p>
        </div>
        <SheetFooter class="flex-row items-center gap-2 border-t">
            <Button type="submit" :disabled="form.processing">{{
                form.processing
                    ? t(
                          'i18n.components.engine.activities.activity_form.saving',
                      )
                    : t('i18n.components.engine.activities.activity_form.save')
            }}</Button>
            <Button
                type="button"
                variant="outline"
                :disabled="form.processing"
                @click="emit('cancel')"
                >{{
                    t('i18n.components.engine.activities.activity_form.cancel')
                }}</Button
            >
        </SheetFooter>
    </form>
</template>
