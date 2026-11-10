<script setup lang="ts">
import DetailItem from '@/components/DetailItem.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import type { MaintenanceLockDetails } from '@/types/maintenance';

const { t } = useI18n();

const props = defineProps<{
    lock: MaintenanceLockDetails | null;
}>();
</script>

<template>
    <Card data-testid="maintenance-status-card">
        <CardHeader>
            <CardTitle>{{
                t(
                    'i18n.components.maintenance.maintenance_lock_status_card.status',
                )
            }}</CardTitle>
            <CardDescription>
                {{
                    t(
                        'i18n.components.maintenance.maintenance_lock_status_card.maintenance_mode_remains_active_until_you_end_it_here',
                    )
                }}
            </CardDescription>
        </CardHeader>
        <CardContent>
            <p
                v-if="props.lock === null"
                class="text-sm text-muted-foreground"
                data-testid="maintenance-inactive"
            >
                {{
                    t(
                        'i18n.components.maintenance.maintenance_lock_status_card.maintenance_mode_is_off',
                    )
                }}
            </p>
            <dl
                v-else
                class="grid gap-4 sm:grid-cols-2"
                data-testid="maintenance-active"
            >
                <DetailItem
                    :label="
                        t(
                            'i18n.components.maintenance.maintenance_lock_status_card.active_since',
                        )
                    "
                >
                    <span data-testid="maintenance-since">
                        {{ formatDateTime(props.lock.acquiredAt) }}
                    </span>
                </DetailItem>
                <DetailItem
                    :label="
                        t(
                            'i18n.components.maintenance.maintenance_lock_status_card.enabled_by',
                        )
                    "
                >
                    <span data-testid="maintenance-acquired-by">
                        {{ props.lock.acquiredByName ?? '—' }}
                    </span>
                </DetailItem>
                <DetailItem
                    :label="
                        t(
                            'i18n.components.maintenance.maintenance_lock_status_card.kind',
                        )
                    "
                >
                    <span data-testid="maintenance-reason">
                        {{ props.lock.reasonLabel }}
                    </span>
                </DetailItem>
                <DetailItem
                    class="sm:col-span-2"
                    :label="
                        t(
                            'i18n.components.maintenance.maintenance_lock_status_card.reason',
                        )
                    "
                >
                    <span
                        class="whitespace-pre-line"
                        data-testid="maintenance-note"
                    >
                        {{ props.lock.note ?? '—' }}
                    </span>
                </DetailItem>
            </dl>
        </CardContent>
    </Card>
</template>
