<script setup lang="ts">
import { Archive, Clock, Mail, MailOpen } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DeleteButton } from '@/components/ui/icon-action-button';
import { useI18n } from '@/composables/useI18n';
import type { NotificationItem } from '@/composables/useNotifications';

const { t } = useI18n();

const props = defineProps<{
    item: NotificationItem;
}>();

const emit = defineEmits<{
    (event: 'read', inbox: string): void;
    (event: 'unread', inbox: string): void;
    (event: 'snooze', inbox: string): void;
    (event: 'archive', inbox: string): void;
    (event: 'delete', inbox: string): void;
}>();

const isRead = computed<boolean>(() => props.item.readAt !== null);

const title = computed<string>(() => {
    const dataTitle = props.item.data?.title;

    if (typeof dataTitle === 'string' && dataTitle.trim().length > 0) {
        return dataTitle;
    }

    const label = props.item.type.replace(/[._]/g, ' ').trim();

    return label.length > 0
        ? label.charAt(0).toUpperCase() + label.slice(1)
        : t('i18n.components.notifications.notification_item.notification');
});

const body = computed<string>(() => {
    const dataBody = props.item.data?.body;

    return typeof dataBody === 'string' ? dataBody : '';
});

const timestamp = computed<string>(() => {
    if (props.item.createdAt === null) {
        return '';
    }

    const date = new Date(props.item.createdAt);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
});

const isHighPriority = computed<boolean>(
    () => props.item.priority === 'high' || props.item.priority === 'urgent',
);
</script>

<template>
    <li
        class="flex items-start gap-3 rounded-md border border-border/60 bg-card p-3"
        :class="isRead ? 'opacity-70' : ''"
    >
        <span
            class="mt-1.5 size-2 shrink-0 rounded-full"
            :class="isRead ? 'bg-transparent' : 'bg-primary'"
            :aria-hidden="true"
        />

        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <div class="flex items-center gap-2">
                <p class="truncate text-sm font-medium">{{ title }}</p>
                <Badge v-if="isHighPriority" variant="destructive">
                    {{
                        t(
                            'i18n.components.notifications.notification_item.important',
                        )
                    }}
                </Badge>
                <span v-if="!isRead" class="sr-only">{{
                    t('i18n.components.notifications.notification_item.unread')
                }}</span>
            </div>
            <p v-if="body" class="text-sm text-muted-foreground">
                {{ body }}
            </p>
            <p v-if="timestamp" class="text-xs text-muted-foreground">
                {{ timestamp }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <Button
                v-if="!isRead"
                variant="ghost"
                size="icon-sm"
                :aria-label="
                    t(
                        'i18n.components.notifications.notification_item.mark_as_read',
                    )
                "
                @click="emit('read', item.id)"
            >
                <MailOpen />
            </Button>
            <Button
                v-else
                variant="ghost"
                size="icon-sm"
                :aria-label="
                    t(
                        'i18n.components.notifications.notification_item.mark_as_unread',
                    )
                "
                @click="emit('unread', item.id)"
            >
                <Mail />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="
                    t('i18n.components.notifications.notification_item.snooze')
                "
                @click="emit('snooze', item.id)"
            >
                <Clock />
            </Button>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="
                    t('i18n.components.notifications.notification_item.archive')
                "
                @click="emit('archive', item.id)"
            >
                <Archive />
            </Button>
            <DeleteButton
                :label="
                    t('i18n.components.notifications.notification_item.delete')
                "
                @click="emit('delete', item.id)"
            />
        </div>
    </li>
</template>
