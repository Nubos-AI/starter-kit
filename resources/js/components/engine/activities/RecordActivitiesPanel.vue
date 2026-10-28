<script setup lang="ts">
import { Link, useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ActivityTypesController from '@/actions/App/Http/Controllers/Engine/ActivityTypesController';
import RecordActivitiesController from '@/actions/App/Http/Controllers/Engine/RecordActivitiesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ActivityForm from '@/components/engine/activities/ActivityForm.vue';
import CreateButton from '@/components/engine/CreateButton.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/lib/formatDate';
import type {
    ActivityListResponse,
    RecordActivityItem,
} from '@/types/activities';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{ recordId: string; readonly?: boolean }>(),
    { readonly: false },
);
const emit = defineEmits<{ changed: [] }>();
const { can } = usePermissions();
const list = useHttp<Record<string, never>, ActivityListResponse>({});
const removal = useHttp({});
const error = ref('');
const deleteError = ref('');
const formOpen = ref(false);
const editing = ref<RecordActivityItem | null>(null);
const pendingDelete = ref<RecordActivityItem | null>(null);
const currentPage = ref(1);
const manageable = computed(
    () => !props.readonly && list.response?.canManage === true,
);
const deleteOpen = computed({
    get: () => pendingDelete.value !== null,
    set: (value: boolean) => {
        if (!value) {
            pendingDelete.value = null;
        }
    },
});
async function load(page = 1): Promise<void> {
    error.value = '';

    try {
        await list.get(
            RecordActivitiesController.index.url(
                { record: props.recordId },
                { query: { page } },
            ),
        );
        currentPage.value = page;
    } catch {
        error.value = t(
            'i18n.components.engine.activities.record_activities_panel.the_activities_could_not_be_loaded_please_try_again',
        );
    }
}
function edit(activity: RecordActivityItem | null): void {
    editing.value = activity;
    formOpen.value = true;
}
async function saved(): Promise<void> {
    formOpen.value = false;
    emit('changed');
    await load();
}
async function remove(): Promise<void> {
    if (!pendingDelete.value) {
        return;
    }

    deleteError.value = '';

    try {
        await removal.delete(
            RecordActivitiesController.destroy.url({
                record: props.recordId,
                activity: pendingDelete.value.id,
            }),
        );
        pendingDelete.value = null;
        emit('changed');
        await load();
    } catch {
        deleteError.value = t(
            'i18n.components.engine.activities.record_activities_panel.the_activity_could_not_be_deleted_please_try_again',
        );
    }
}
onBeforeUnmount(() => {
    list.cancel();
    removal.cancel();
});
watch(
    () => props.recordId,
    () => {
        formOpen.value = false;
        list.response = null;
        void load();
    },
    { immediate: true },
);
</script>

<template>
    <Card data-record-activities-panel>
        <CardHeader>
            <CardTitle>{{
                t(
                    'i18n.components.engine.activities.record_activities_panel.activities',
                )
            }}</CardTitle>
            <CardDescription>{{
                t(
                    'i18n.components.engine.activities.record_activities_panel.calls_appointments_and_outcomes_for_this_record',
                )
            }}</CardDescription>
            <CardAction
                v-if="manageable && list.response?.activityTypes.length"
            >
                <CreateButton
                    :label="
                        t(
                            'i18n.components.engine.activities.record_activities_panel.new_activity',
                        )
                    "
                    data-activity-new
                    @create="edit(null)"
                />
            </CardAction>
        </CardHeader>
        <CardContent class="flex flex-col gap-6">
            <p v-if="readonly" class="text-sm text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.activities.record_activities_panel.activities_on_a_deleted_record_can_no_longer_be',
                    )
                }}
            </p>
            <div v-if="error" role="alert" class="flex flex-col gap-2">
                <p class="text-sm text-destructive">{{ error }}</p>
                <Button
                    variant="outline"
                    class="self-start"
                    @click="load(currentPage)"
                    >{{
                        t(
                            'i18n.components.engine.activities.record_activities_panel.try_again',
                        )
                    }}</Button
                >
            </div>
            <Skeleton
                v-if="list.processing && !list.response"
                class="h-24 w-full"
                :aria-label="
                    t(
                        'i18n.components.engine.activities.record_activities_panel.loading_activities',
                    )
                "
            />
            <template v-if="list.response">
                <Sheet v-model:open="formOpen">
                    <SheetContent side="right" class="w-full gap-0 sm:max-w-md">
                        <ActivityForm
                            :key="editing?.id ?? 'new'"
                            :record-id="recordId"
                            :activity="editing"
                            :types="list.response.activityTypes"
                            :assignees="list.response.assignees"
                            @saved="saved"
                            @cancel="formOpen = false"
                        />
                    </SheetContent>
                </Sheet>
                <p
                    v-if="!list.response.activityTypes.length && manageable"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'i18n.components.engine.activities.record_activities_panel.before_creating_activities_add_an_activity_type_under_configuration',
                        )
                    }}
                    <Link
                        v-if="can('activity-types.create')"
                        :href="ActivityTypesController.create.url()"
                        class="underline underline-offset-2"
                        >{{
                            t(
                                'i18n.components.engine.activities.record_activities_panel.create_activity_type',
                            )
                        }}</Link
                    >
                </p>
                <p
                    v-if="!list.response.data.length"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'i18n.components.engine.activities.record_activities_panel.no_activities_recorded_yet',
                        )
                    }}
                </p>
                <ul v-else class="flex flex-col divide-y">
                    <li
                        v-for="activity in list.response.data"
                        :key="activity.id"
                        class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0"
                        data-activity-item
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-medium break-words">
                                    {{ activity.subject }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        activity.typeName ??
                                        t(
                                            'i18n.components.engine.activities.record_activities_panel.no_type',
                                        )
                                    }}
                                    ·
                                    {{ formatDateTime(activity.occurredAt) }} ·
                                    {{
                                        activity.assigneeName ??
                                        t(
                                            'i18n.components.engine.activities.record_activities_panel.unassigned',
                                        )
                                    }}
                                </p>
                            </div>
                            <div v-if="manageable" class="flex shrink-0 gap-1">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :disabled="formOpen"
                                    @click="edit(activity)"
                                    >{{
                                        t(
                                            'i18n.components.engine.activities.record_activities_panel.edit',
                                        )
                                    }}</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :disabled="formOpen"
                                    @click="pendingDelete = activity"
                                    >{{
                                        t(
                                            'i18n.components.engine.activities.record_activities_panel.delete',
                                        )
                                    }}</Button
                                >
                            </div>
                        </div>
                        <p
                            v-if="activity.result"
                            class="text-sm break-words whitespace-pre-wrap"
                        >
                            {{ activity.result }}
                        </p>
                    </li>
                </ul>
                <div
                    v-if="list.response.meta.last_page > 1"
                    class="flex items-center justify-end gap-3"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="currentPage === 1 || list.processing"
                        @click="load(currentPage - 1)"
                        >{{
                            t(
                                'i18n.components.engine.activities.record_activities_panel.back',
                            )
                        }}</Button
                    >
                    <span class="text-sm"
                        >{{
                            t(
                                'i18n.components.engine.activities.record_activities_panel.page',
                            )
                        }}
                        {{ currentPage }}
                        {{
                            t(
                                'i18n.components.engine.activities.record_activities_panel.of',
                            )
                        }}
                        {{ list.response.meta.last_page }}</span
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            currentPage === list.response.meta.last_page ||
                            list.processing
                        "
                        @click="load(currentPage + 1)"
                        >{{
                            t(
                                'i18n.components.engine.activities.record_activities_panel.continue',
                            )
                        }}</Button
                    >
                </div>
            </template>
            <ConfirmDialog
                v-model:open="deleteOpen"
                :title="
                    t(
                        'i18n.components.engine.activities.record_activities_panel.delete_activity',
                    )
                "
                :description="
                    t(
                        'i18n.components.engine.activities.record_activities_panel.the_activity_will_be_removed_from_the_record_and',
                        { value1: pendingDelete?.subject ?? '' },
                    )
                "
                :confirm-label="
                    t(
                        'i18n.components.engine.activities.record_activities_panel.delete',
                    )
                "
                variant="destructive"
                :pending="removal.processing"
                @confirm="remove"
                ><p
                    v-if="deleteError"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ deleteError }}
                </p></ConfirmDialog
            >
        </CardContent>
    </Card>
</template>
