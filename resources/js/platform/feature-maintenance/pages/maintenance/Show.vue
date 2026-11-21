<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MaintenanceLockController from '@/actions/App/Http/Controllers/Maintenance/MaintenanceLockController';
import { useI18n } from '@/shared/composables/useI18n';
import type { MaintenanceLockDetails } from '@/shared/types/maintenance';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Textarea } from '@/shared/ui/primitives/textarea';
import MaintenanceLockStatusCard from '../../ui/MaintenanceLockStatusCard.vue';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    lock?: MaintenanceLockDetails | null;
  }>(),
  { lock: null },
);

const form = useForm<{ note: string }>({ note: '' });

const isReleaseDialogOpen = ref<boolean>(false);
const releasePending = ref<boolean>(false);

const isActive = computed<boolean>(() => props.lock !== null);

function acquire(): void {
  form.post(MaintenanceLockController.store.url(), {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });
}

function confirmRelease(): void {
  releasePending.value = true;

  router.delete(MaintenanceLockController.destroy.url(), {
    preserveScroll: true,
    onFinish: () => {
      releasePending.value = false;
      isReleaseDialogOpen.value = false;
    },
  });
}
</script>

<template>
  <PageShell
    :title="t('i18n.pages.maintenance.show.maintenance_mode')"
    :description="
      t('i18n.pages.maintenance.show.maintenance_mode_blocks_writes_api_writes_background_processes_and_schedules')
    "
  >
    <MaintenanceLockStatusCard :lock="props.lock" />

    <Card v-if="!isActive" data-testid="maintenance-acquire-card">
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.maintenance.show.enable_maintenance_mode') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.maintenance.show.provide_a_reason_it_will_appear_in_the_audit') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form class="grid gap-4" @submit.prevent="acquire">
          <FormField for="maintenance-note" :label="t('i18n.pages.maintenance.show.reason')" :error="form.errors.note">
            <Textarea
              id="maintenance-note"
              v-model="form.note"
              data-testid="maintenance-note-input"
              rows="3"
              maxlength="1000"
            />
          </FormField>
          <div class="flex justify-end">
            <Button type="submit" data-testid="maintenance-acquire" :disabled="form.processing">
              {{ t('i18n.pages.maintenance.show.enable_maintenance_mode') }}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>

    <Card v-else data-testid="maintenance-release-card">
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.maintenance.show.end_maintenance_mode') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.maintenance.show.after_ending_maintenance_mode_writes_are_allowed_again_and') }}
        </CardDescription>
      </CardHeader>
      <CardContent class="flex justify-end">
        <Button data-testid="maintenance-release" @click="isReleaseDialogOpen = true">
          {{ t('i18n.pages.maintenance.show.end_maintenance_mode') }}
        </Button>
      </CardContent>
    </Card>

    <ConfirmDialog
      v-model:open="isReleaseDialogOpen"
      :title="t('i18n.pages.maintenance.show.end_maintenance_mode_2')"
      :description="
        t('i18n.pages.maintenance.show.writes_background_processes_and_schedules_for_this_tenant_will_resume')
      "
      :confirm-label="t('i18n.pages.maintenance.show.end')"
      :pending="releasePending"
      @confirm="confirmRelease"
    />
  </PageShell>
</template>
