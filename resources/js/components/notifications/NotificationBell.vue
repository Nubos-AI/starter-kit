<script setup lang="ts">
import { Bell } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import NotificationInbox from '@/components/notifications/NotificationInbox.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useNotifications } from '@/composables/useNotifications';

const { t } = useI18n();

const { unreadCount, startPolling } = useNotifications();

const open = ref<boolean>(false);

const badgeLabel = computed<string>(() =>
    unreadCount.value > 99 ? '99+' : String(unreadCount.value),
);

const accessibleLabel = computed<string>(() =>
    unreadCount.value > 0
        ? t(
              'i18n.components.notifications.notification_bell.notifications_unread',
              { value1: unreadCount.value },
          )
        : t('i18n.components.notifications.notification_bell.notifications'),
);

onMounted(() => {
    startPolling();
});
</script>

<template>
    <div class="relative">
        <Button
            variant="ghost"
            size="icon"
            :aria-label="accessibleLabel"
            aria-haspopup="dialog"
            :aria-expanded="open"
            @click="open = true"
        >
            <Bell />
        </Button>
        <span
            v-if="unreadCount > 0"
            class="pointer-events-none absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-4 font-semibold text-white"
            aria-hidden="true"
        >
            {{ badgeLabel }}
        </span>

        <NotificationInbox v-model:open="open" />
    </div>
</template>
