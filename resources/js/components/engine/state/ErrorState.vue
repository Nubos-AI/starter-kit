<script setup lang="ts">
import { RefreshCw, TriangleAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

withDefaults(
    defineProps<{
        title: string;
        defaultMessage: string;
        message?: string;
        retryLabel?: string;
        retryAttrs?: Record<string, string>;
    }>(),
    {
        message: undefined,
        retryLabel: undefined,
        retryAttrs: undefined,
    },
);

const emit = defineEmits<{
    retry: [];
}>();
</script>

<template>
    <div class="contents">
        <Alert variant="destructive">
            <TriangleAlert />
            <AlertTitle>{{ title }}</AlertTitle>
            <AlertDescription>{{ message ?? defaultMessage }}</AlertDescription>
        </Alert>
        <Button
            variant="outline"
            class="self-start"
            v-bind="retryAttrs"
            @click="emit('retry')"
        >
            <RefreshCw />
            {{
                retryLabel ??
                t('i18n.components.engine.state.error_state.try_again')
            }}
        </Button>
    </div>
</template>
