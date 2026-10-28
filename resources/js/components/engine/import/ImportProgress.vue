<script setup lang="ts">
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { useJobProgress } from '@/composables/useJobProgress';
import type {
    JobExecutePayload,
    JobProgressContext,
    JobStatus,
} from '@/composables/useJobProgress';

const { t } = useI18n();

const job = useJobProgress();

const statusLabels: Record<JobStatus, string> = {
    idle: t('i18n.components.engine.import.import_progress.ready'),
    polling: t('i18n.components.engine.import.import_progress.importing'),
    finished: t(
        'i18n.components.engine.import.import_progress.import_complete',
    ),
    failed: t('i18n.components.engine.import.import_progress.import_failed'),
    cancelled: t(
        'i18n.components.engine.import.import_progress.import_cancelled',
    ),
};

const progressValue = computed<number>(() =>
    Math.max(0, Math.min(100, Math.round(job.progress.value))),
);

function start(payload: JobExecutePayload, context: JobProgressContext): void {
    job.start(payload, context);
}

defineExpose({
    start,
    stop: job.stop,
    status: job.status,
    active: job.active,
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-sm font-semibold">
                {{ statusLabels[job.status.value] }}
            </CardTitle>
        </CardHeader>

        <CardContent class="flex flex-col gap-4">
            <div
                v-if="job.status.value === 'polling'"
                class="flex flex-col gap-3"
                role="status"
                aria-live="polite"
                :aria-busy="true"
            >
                <div
                    class="h-2 w-full overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="progressValue"
                    :aria-valuemin="0"
                    :aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-all"
                        :style="{ width: `${progressValue}%` }"
                    />
                </div>
                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.components.engine.import.import_progress.processed',
                                )
                            }}
                        </dt>
                        <dd class="font-medium">
                            {{ job.processedJobs.value }} /
                            {{ job.totalJobs.value }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.components.engine.import.import_progress.failed',
                                )
                            }}
                        </dt>
                        <dd class="font-medium">{{ job.failedJobs.value }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.components.engine.import.import_progress.progress',
                                )
                            }}
                        </dt>
                        <dd class="font-medium">{{ progressValue }}%</dd>
                    </div>
                </dl>
            </div>

            <Alert
                v-else-if="job.status.value === 'failed'"
                variant="destructive"
                role="alert"
            >
                <AlertTitle>{{
                    t(
                        'i18n.components.engine.import.import_progress.import_failed',
                    )
                }}</AlertTitle>
                <AlertDescription>
                    {{
                        t(
                            'i18n.components.engine.import.import_progress.the_import_could_not_be_completed_please_try_again',
                        )
                    }}
                </AlertDescription>
            </Alert>

            <Alert v-else-if="job.status.value === 'cancelled'" role="alert">
                <AlertTitle>{{
                    t(
                        'i18n.components.engine.import.import_progress.import_cancelled',
                    )
                }}</AlertTitle>
                <AlertDescription>
                    {{
                        t(
                            'i18n.components.engine.import.import_progress.the_import_run_was_cancelled',
                        )
                    }}
                </AlertDescription>
            </Alert>

            <template v-else-if="job.status.value === 'finished'">
                <dl class="grid grid-cols-3 gap-3">
                    <div class="rounded-md border p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.components.engine.import.import_progress.new',
                                )
                            }}
                        </dt>
                        <dd class="text-2xl font-semibold">
                            {{ job.createdCount.value }}
                        </dd>
                    </div>
                    <div class="rounded-md border p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.components.engine.import.import_progress.updated',
                                )
                            }}
                        </dt>
                        <dd class="text-2xl font-semibold">
                            {{ job.updatedCount.value }}
                        </dd>
                    </div>
                    <div class="rounded-md border p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.components.engine.import.import_progress.errors',
                                )
                            }}
                        </dt>
                        <dd class="text-2xl font-semibold">
                            {{ job.errorCount.value }}
                        </dd>
                    </div>
                </dl>

                <Button
                    v-if="job.errorReportUrl.value !== null"
                    as="a"
                    variant="outline"
                    size="sm"
                    :href="job.errorReportUrl.value"
                    class="self-start"
                >
                    {{
                        t(
                            'i18n.components.engine.import.import_progress.download_error_report',
                        )
                    }}
                </Button>
            </template>

            <p v-else class="text-sm text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.import.import_progress.the_import_has_not_been_started_yet',
                    )
                }}
            </p>
        </CardContent>
    </Card>
</template>
