<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import FormActions from '@/components/FormActions.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { DashboardRow } from '@/types/dashboards';

const { t } = useI18n();

const SHEET_TITLE = t(
    'i18n.components.dashboards.dashboard_details_sheet.basic_information',
);

const SHEET_DESCRIPTION = t(
    'i18n.components.dashboards.dashboard_details_sheet.the_name_and_description_appear_in_the_list_and',
);

const SAVED_MESSAGE = t(
    'i18n.components.dashboards.dashboard_details_sheet.the_dashboard_was_saved',
);

const props = defineProps<{
    dashboard: DashboardRow;
    open: boolean;
}>();

const emit = defineEmits<{
    close: [];
}>();

const name = ref<string>(props.dashboard.name);
const description = ref<string>(props.dashboard.description ?? '');
const closeRequested = ref<boolean>(false);

const form = useForm<Record<string, string>>({});

const { isDirty, promptOpen, confirmLeave, cancelLeave, markSaved } =
    useUnsavedChanges({
        values: () => ({ name: name.value, description: description.value }),
        backHref: DashboardsController.show.url({
            dashboard: props.dashboard.id,
        }),
    });

function seed(): void {
    name.value = props.dashboard.name;
    description.value = props.dashboard.description ?? '';
    markSaved();
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            seed();
        }
    },
);

function submitPayload(): Record<string, string | null> {
    return {
        name: name.value,
        description: description.value.trim() === '' ? null : description.value,
    };
}

function onSubmit(): void {
    form.transform(() => submitPayload()).put(
        DashboardsController.update.url({ dashboard: props.dashboard.id }),
        {
            preserveScroll: true,
            onSuccess: (): void => {
                markSaved();
                toast.success(SAVED_MESSAGE);
                emit('close');
            },
        },
    );
}

function onCancel(): void {
    if (!isDirty.value) {
        emit('close');

        return;
    }

    closeRequested.value = true;
}

function onConfirmLeave(): void {
    if (closeRequested.value) {
        closeRequested.value = false;
        seed();
        emit('close');

        return;
    }

    confirmLeave();
}

function onCancelLeave(): void {
    closeRequested.value = false;
    cancelLeave();
}

function onOpenChange(next: boolean): void {
    if (!next) {
        onCancel();
    }
}
</script>

<template>
    <div>
        <Sheet :open="props.open" @update:open="onOpenChange">
            <SheetContent side="right" class="w-full sm:max-w-md">
                <div
                    data-dashboard-details
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4"
                >
                    <SheetHeader class="px-0">
                        <SheetTitle>{{ SHEET_TITLE }}</SheetTitle>
                        <SheetDescription>
                            {{ SHEET_DESCRIPTION }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="grid gap-2">
                        <Label for="dashboard-name">{{
                            t(
                                'i18n.components.dashboards.dashboard_details_sheet.name',
                            )
                        }}</Label>
                        <Input
                            id="dashboard-name"
                            v-model="name"
                            :placeholder="
                                t(
                                    'i18n.components.dashboards.dashboard_details_sheet.dashboard_name',
                                )
                            "
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="dashboard-description">{{
                            t(
                                'i18n.components.dashboards.dashboard_details_sheet.description',
                            )
                        }}</Label>
                        <Textarea
                            id="dashboard-description"
                            v-model="description"
                            :placeholder="
                                t(
                                    'i18n.components.dashboards.dashboard_details_sheet.what_is_this_dashboard_for',
                                )
                            "
                            rows="2"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <FormActions
                        type="button"
                        :dirty="isDirty"
                        :processing="form.processing"
                        @cancel="onCancel"
                        @save="onSubmit"
                    />
                </div>
            </SheetContent>
        </Sheet>

        <UnsavedChangesDialog
            :open="promptOpen || closeRequested"
            @confirm="onConfirmLeave"
            @cancel="onCancelLeave"
        />
    </div>
</template>
