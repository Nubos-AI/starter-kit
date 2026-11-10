<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { MaintenanceState } from '@/types/maintenance';

const { t } = useI18n();

const page = usePage();

const maintenance = computed<MaintenanceState | null>(
    () => page.props?.maintenance ?? null,
);
</script>

<template>
    <div
        v-if="maintenance"
        data-maintenance-banner
        role="status"
        class="border-b border-warning bg-warning-subtle px-3 py-2 text-warning-bolder"
    >
        {{ t('i18n.components.maintenance.maintenance_lock_banner.for') }}
        <strong data-maintenance-tenant>{{ maintenance.tenantName }}</strong>
        {{
            t(
                'i18n.components.maintenance.maintenance_lock_banner.maintenance_mode_is_active_writes_api_writes_automations_and',
            )
        }}
    </div>
</template>
