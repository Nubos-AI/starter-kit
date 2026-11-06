<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import type { DryRunResult } from '@/composables/useImportWizard';

const { t } = useI18n();

defineProps<{
    dryRunResult: DryRunResult | null;
    loading: boolean;
    error: string | null;
    canStartImport: boolean;
    canImport: boolean;
}>();

const emit = defineEmits<{
    run: [];
    start: [];
}>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">
                {{
                    t(
                        'i18n.components.engine.import.dry_run_summary.preview_dry_run',
                    )
                }}
            </h3>
            <Button
                variant="outline"
                size="sm"
                :disabled="loading"
                @click="emit('run')"
            >
                {{
                    t(
                        'i18n.components.engine.import.dry_run_summary.refresh_preview',
                    )
                }}
            </Button>
        </div>

        <div
            v-if="loading"
            class="flex gap-3"
            role="status"
            aria-live="polite"
            aria-busy="true"
        >
            <span class="sr-only">{{
                t(
                    'i18n.components.engine.import.dry_run_summary.calculating_preview',
                )
            }}</span>
            <Skeleton class="h-16 w-28" />
            <Skeleton class="h-16 w-28" />
            <Skeleton class="h-16 w-28" />
        </div>

        <Alert v-else-if="error" variant="destructive" role="alert">
            <AlertTitle>{{
                t(
                    'i18n.components.engine.import.dry_run_summary.preview_failed',
                )
            }}</AlertTitle>
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <template v-else-if="dryRunResult">
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-md border p-3">
                    <p class="text-2xl font-semibold">{{ dryRunResult.new }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.engine.import.dry_run_summary.new',
                            )
                        }}
                    </p>
                </div>
                <div class="rounded-md border p-3">
                    <p class="text-2xl font-semibold">
                        {{ dryRunResult.updates }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.engine.import.dry_run_summary.updates',
                            )
                        }}
                    </p>
                </div>
                <div class="rounded-md border p-3">
                    <p class="text-2xl font-semibold">
                        {{ dryRunResult.errors }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.engine.import.dry_run_summary.errors',
                            )
                        }}
                    </p>
                </div>
            </div>

            <Alert v-if="!dryRunResult.duplicateDetection" role="status">
                <AlertTitle>{{
                    t(
                        'i18n.components.engine.import.dry_run_summary.duplicate_detection_unavailable',
                    )
                }}</AlertTitle>
                <AlertDescription>
                    {{
                        t(
                            'i18n.components.engine.import.dry_run_summary.no_field_is_marked_as_unique_for_this_object',
                        )
                    }}
                </AlertDescription>
            </Alert>

            <div
                v-if="dryRunResult.sampleErrors.length > 0"
                class="flex flex-col gap-2"
            >
                <p class="text-sm font-medium">
                    {{
                        t(
                            'i18n.components.engine.import.dry_run_summary.sample_errors',
                        )
                    }}
                </p>
                <ul class="flex flex-col gap-1">
                    <li
                        v-for="sample in dryRunResult.sampleErrors"
                        :key="`${sample.row}-${sample.message}`"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Badge variant="outline"
                            >{{
                                t(
                                    'i18n.components.engine.import.dry_run_summary.row',
                                )
                            }}
                            {{ sample.row }}</Badge
                        >
                        <span class="text-muted-foreground">{{
                            sample.message
                        }}</span>
                    </li>
                </ul>
            </div>
        </template>

        <p v-else class="text-sm text-muted-foreground">
            {{
                t(
                    'i18n.components.engine.import.dry_run_summary.no_preview_yet_start_a_dry_run_to_see',
                )
            }}
        </p>

        <div>
            <Button
                :disabled="!canStartImport || !canImport"
                @click="emit('start')"
            >
                {{
                    t(
                        'i18n.components.engine.import.dry_run_summary.start_import',
                    )
                }}
            </Button>
            <p v-if="!canImport" class="mt-1 text-xs text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.import.dry_run_summary.you_do_not_have_permission_to_import_records',
                    )
                }}
            </p>
        </div>
    </div>
</template>
