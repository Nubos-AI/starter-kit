<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import NotificationItem from '@/components/notifications/NotificationItem.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import { useNotifications } from '@/composables/useNotifications';
import type { NotificationInboxState } from '@/composables/useNotifications';

const { t } = useI18n();

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (event: 'update:open', value: boolean): void;
}>();

interface InboxTab {
    key: NotificationInboxState;
    label: string;
    emptyLabel: string;
}

const tabs: InboxTab[] = [
    {
        key: 'messages',
        label: t('i18n.components.notifications.notification_inbox.messages'),
        emptyLabel: t(
            'i18n.components.notifications.notification_inbox.no_messages_available',
        ),
    },
    {
        key: 'snoozed',
        label: t('i18n.components.notifications.notification_inbox.snoozed'),
        emptyLabel: t(
            'i18n.components.notifications.notification_inbox.nothing_snoozed',
        ),
    },
    {
        key: 'archived',
        label: t('i18n.components.notifications.notification_inbox.archived'),
        emptyLabel: t(
            'i18n.components.notifications.notification_inbox.no_archive_available',
        ),
    },
];

const activeTab = ref<NotificationInboxState>('messages');

const {
    items,
    loading,
    error,
    load,
    markRead,
    markUnread,
    snooze,
    archive,
    remove,
} = useNotifications();

const activeEmptyLabel = computed<string>(
    () =>
        tabs.find((tab) => tab.key === activeTab.value)?.emptyLabel ??
        t('i18n.components.notifications.notification_inbox.no_notifications'),
);

const isOpen = computed<boolean>({
    get: () => props.open,
    set: (value) => emit('update:open', value),
});

const selectTab = (key: NotificationInboxState): void => {
    activeTab.value = key;
    void load(key);
};

const onTabKeydown = (event: KeyboardEvent, index: number): void => {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
        return;
    }

    event.preventDefault();

    const direction = event.key === 'ArrowRight' ? 1 : -1;
    const nextIndex = (index + direction + tabs.length) % tabs.length;
    const nextTab = tabs[nextIndex];

    selectTab(nextTab.key);

    const target = document.getElementById(`notification-tab-${nextTab.key}`);
    target?.focus();
};

const snoozeItem = (inbox: string): void => {
    const until = new Date(Date.now() + 60 * 60 * 1000).toISOString();
    void snooze(inbox, until).then(() => load(activeTab.value));
};

const readItem = (inbox: string): void => {
    void markRead(inbox).then(() => load(activeTab.value));
};

const unreadItem = (inbox: string): void => {
    void markUnread(inbox).then(() => load(activeTab.value));
};

const archiveItem = (inbox: string): void => {
    void archive(inbox).then(() => load(activeTab.value));
};

const deleteItem = (inbox: string): void => {
    void remove(inbox);
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            void load(activeTab.value);
        }
    },
);
</script>

<template>
    <Sheet v-model:open="isOpen">
        <SheetContent side="right" class="w-full gap-0 sm:max-w-md">
            <SheetHeader>
                <SheetTitle>{{
                    t(
                        'i18n.components.notifications.notification_inbox.notifications',
                    )
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        t(
                            'i18n.components.notifications.notification_inbox.read_snooze_or_archive_notifications',
                        )
                    }}
                </SheetDescription>
            </SheetHeader>

            <div
                role="tablist"
                :aria-label="
                    t(
                        'i18n.components.notifications.notification_inbox.notification_views',
                    )
                "
                class="flex gap-1 border-b border-border px-4"
            >
                <Button
                    v-for="(tab, index) in tabs"
                    :id="`notification-tab-${tab.key}`"
                    :key="tab.key"
                    type="button"
                    variant="ghost"
                    size="sm"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    :tabindex="activeTab === tab.key ? 0 : -1"
                    :aria-controls="`notification-panel-${tab.key}`"
                    class="rounded-none border-b-2 px-3 py-2 hover:bg-transparent"
                    :class="
                        activeTab === tab.key
                            ? 'border-primary text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    @click="selectTab(tab.key)"
                    @keydown="onTabKeydown($event, index)"
                >
                    {{ tab.label }}
                </Button>
            </div>

            <div
                :id="`notification-panel-${activeTab}`"
                role="tabpanel"
                :aria-labelledby="`notification-tab-${activeTab}`"
                class="flex flex-1 flex-col gap-2 overflow-y-auto p-4"
            >
                <div
                    v-if="loading"
                    class="flex flex-col gap-2"
                    aria-hidden="true"
                >
                    <Skeleton
                        v-for="placeholder in 4"
                        :key="placeholder"
                        class="h-16 w-full rounded-md"
                    />
                </div>
                <p v-if="loading" class="sr-only" role="status">
                    {{
                        t(
                            'i18n.components.notifications.notification_inbox.loading_notifications',
                        )
                    }}
                </p>

                <Alert v-else-if="error" variant="destructive" role="alert">
                    <AlertTitle>{{
                        t(
                            'i18n.components.notifications.notification_inbox.errors',
                        )
                    }}</AlertTitle>
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>

                <p
                    v-else-if="items.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {{ activeEmptyLabel }}
                </p>

                <ul v-else class="flex flex-col gap-2">
                    <NotificationItem
                        v-for="item in items"
                        :key="item.id"
                        :item="item"
                        @read="readItem"
                        @unread="unreadItem"
                        @snooze="snoozeItem"
                        @archive="archiveItem"
                        @delete="deleteItem"
                    />
                </ul>
            </div>
        </SheetContent>
    </Sheet>
</template>
