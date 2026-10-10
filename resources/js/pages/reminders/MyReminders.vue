<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CreateButton from '@/components/engine/CreateButton.vue';
import ReminderForm from '@/components/engine/reminders/ReminderForm.vue';
import ReminderGrid from '@/components/engine/reminders/ReminderGrid.vue';
import ViewStates from '@/components/engine/ViewStates.vue';
import type { RecordViewState } from '@/components/engine/ViewStates.vue';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { useReminders } from '@/composables/useReminders';
import type { ReminderItem } from '@/composables/useReminders';

const { t } = useI18n();

const ALL_TYPES = '__all__';

interface TypeOption {
    value: string;
    label: string;
}

const page = usePage<{ reminderTypeOptions?: TypeOption[] }>();

const { items, loading, error, loadMyOpen, complete, destroy, bulkDestroy } =
    useReminders();

const formOpen = ref<boolean>(false);
const editing = ref<ReminderItem | null>(null);
const typeFilter = ref<string>(ALL_TYPES);

const availableTypes = computed<TypeOption[]>(
    () => page.props.reminderTypeOptions ?? [],
);

const filteredItems = computed<ReminderItem[]>(() => {
    if (typeFilter.value === ALL_TYPES) {
        return items.value;
    }

    return items.value.filter((item) => item.type?.id === typeFilter.value);
});

const hasReminders = computed<boolean>(() => items.value.length > 0);

const viewState = computed<RecordViewState>(() => {
    if (loading.value) {
        return 'loading';
    }

    if (error.value !== null) {
        return 'error';
    }

    return filteredItems.value.length === 0 ? 'empty' : 'ready';
});

const emptyKind = computed<'no-records' | 'no-filter-match'>(() =>
    typeFilter.value === ALL_TYPES ? 'no-records' : 'no-filter-match',
);

const emptyTitle = computed<string | undefined>(() =>
    emptyKind.value === 'no-records'
        ? t('i18n.pages.reminders.my_reminders.no_reminders_yet')
        : undefined,
);

const emptyDescription = computed<string | undefined>(() =>
    emptyKind.value === 'no-records'
        ? t(
              'i18n.pages.reminders.my_reminders.create_your_first_reminder_to_get_started',
          )
        : undefined,
);

function reload(): void {
    void loadMyOpen();
}

function clearFilter(): void {
    typeFilter.value = ALL_TYPES;
}

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(item: ReminderItem): void {
    editing.value = item;
    formOpen.value = true;
}

async function onComplete(id: string): Promise<void> {
    const done = await complete(id);

    if (done !== null) {
        reload();
    }
}

async function onDelete(id: string): Promise<void> {
    const removed = await destroy(id);

    if (removed) {
        reload();
    }
}

async function onBulkDelete(ids: string[]): Promise<void> {
    const removed = await bulkDestroy(ids);

    if (removed) {
        reload();
    }
}

onMounted(reload);
</script>

<template>
    <Head :title="t('i18n.pages.reminders.my_reminders.my_open_reminders')" />

    <div class="flex h-full flex-1 flex-col gap-4 p-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-lg font-semibold">
                {{ t('i18n.pages.reminders.my_reminders.my_open_reminders') }}
            </h1>

            <div class="flex items-center gap-2">
                <template v-if="hasReminders">
                    <Label for="reminder-type-filter" class="text-sm">
                        {{ t('i18n.pages.reminders.my_reminders.type') }}
                    </Label>
                    <Select v-model="typeFilter">
                        <SelectTrigger id="reminder-type-filter" class="w-44">
                            <SelectValue
                                :placeholder="
                                    t(
                                        'i18n.pages.reminders.my_reminders.all_types',
                                    )
                                "
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem :value="ALL_TYPES">
                                    {{
                                        t(
                                            'i18n.pages.reminders.my_reminders.all_types',
                                        )
                                    }}
                                </SelectItem>
                                <SelectItem
                                    v-for="type in availableTypes"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </template>

                <CreateButton
                    :label="t('i18n.pages.reminders.my_reminders.new_reminder')"
                    data-reminder-new
                    @create="openCreate"
                />
            </div>
        </div>

        <div class="relative min-h-0 flex-1">
            <ReminderGrid
                v-if="viewState === 'ready'"
                :items="filteredItems"
                @edit="openEdit"
                @complete="onComplete"
                @delete="onDelete"
                @bulk-delete="onBulkDelete"
            />
            <ViewStates
                :state="viewState"
                skeleton-variant="table"
                :empty-kind="emptyKind"
                :error-message="error ?? undefined"
                :empty-title="emptyTitle"
                :empty-description="emptyDescription"
                create-label="Erste Erinnerung anlegen"
                @retry="reload"
                @create="openCreate"
                @clear-filter="clearFilter"
            />
        </div>
    </div>

    <ReminderForm v-model:open="formOpen" :reminder="editing" @saved="reload" />
</template>
