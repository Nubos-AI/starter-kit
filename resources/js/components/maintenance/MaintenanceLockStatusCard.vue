<script setup lang="ts">
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
                <div class="grid gap-1">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.maintenance.maintenance_lock_status_card.active_since',
                            )
                        }}
                    </dt>
                    <dd data-testid="maintenance-since">
                        {{ formatDateTime(props.lock.acquiredAt) }}
                    </dd>
                </div>
                <div class="grid gap-1">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.maintenance.maintenance_lock_status_card.enabled_by',
                            )
                        }}
                    </dt>
                    <dd data-testid="maintenance-acquired-by">
                        {{ props.lock.acquiredByName ?? '—' }}
                    </dd>
                </div>
                <div class="grid gap-1">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.maintenance.maintenance_lock_status_card.kind',
                            )
                        }}
                    </dt>
                    <dd data-testid="maintenance-reason">
                        {{ props.lock.reasonLabel }}
                    </dd>
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.maintenance.maintenance_lock_status_card.reason',
                            )
                        }}
                    </dt>
                    <dd
                        class="whitespace-pre-line"
                        data-testid="maintenance-note"
                    >
                        {{ props.lock.note ?? '—' }}
                    </dd>
                </div>
            </dl>
        </CardContent>
    </Card>
</template>
