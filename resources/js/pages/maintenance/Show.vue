<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MaintenanceLockController from '@/actions/App/Http/Controllers/Maintenance/MaintenanceLockController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import MaintenanceLockStatusCard from '@/components/maintenance/MaintenanceLockStatusCard.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type { MaintenanceLockDetails } from '@/types/maintenance';

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
    <div class="flex flex-col gap-6 p-3">
        <Head :title="t('i18n.pages.maintenance.show.maintenance_mode')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.maintenance.show.maintenance_mode')"
            :description="
                t(
                    'i18n.pages.maintenance.show.maintenance_mode_blocks_writes_api_writes_automations_and_schedules',
                )
            "
        />

        <MaintenanceLockStatusCard :lock="props.lock" />

        <Card v-if="!isActive" data-testid="maintenance-acquire-card">
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.maintenance.show.enable_maintenance_mode')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.maintenance.show.provide_a_reason_it_will_appear_in_the_audit',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form class="grid gap-4" @submit.prevent="acquire">
                    <div class="grid gap-2">
                        <Label for="maintenance-note">{{
                            t('i18n.pages.maintenance.show.reason')
                        }}</Label>
                        <Textarea
                            id="maintenance-note"
                            v-model="form.note"
                            data-testid="maintenance-note-input"
                            rows="3"
                            maxlength="1000"
                        />
                        <InputError :message="form.errors.note" />
                    </div>
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            data-testid="maintenance-acquire"
                            :disabled="form.processing"
                        >
                            {{
                                t(
                                    'i18n.pages.maintenance.show.enable_maintenance_mode',
                                )
                            }}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card v-else data-testid="maintenance-release-card">
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.maintenance.show.end_maintenance_mode')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.maintenance.show.after_ending_maintenance_mode_writes_are_allowed_again_and',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex justify-end">
                <Button
                    data-testid="maintenance-release"
                    @click="isReleaseDialogOpen = true"
                >
                    {{ t('i18n.pages.maintenance.show.end_maintenance_mode') }}
                </Button>
            </CardContent>
        </Card>

        <ConfirmDialog
            v-model:open="isReleaseDialogOpen"
            :title="t('i18n.pages.maintenance.show.end_maintenance_mode_2')"
            :description="
                t(
                    'i18n.pages.maintenance.show.writes_automations_and_schedules_for_this_tenant_will_resume',
                )
            "
            :confirm-label="t('i18n.pages.maintenance.show.end')"
            :pending="releasePending"
            @confirm="confirmRelease"
        />
    </div>
</template>
