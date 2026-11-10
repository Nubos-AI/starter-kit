<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        currentPage: number;
        lastPage: number;
        isDisabled?: boolean;
    }>(),
    { isDisabled: false },
);

const emit = defineEmits<{
    'change-page': [page: number];
}>();
</script>

<template>
    <div
        v-if="props.lastPage > 1"
        class="flex items-center justify-end gap-3"
        data-testid="list-pager"
    >
        <Button
            variant="outline"
            size="sm"
            data-testid="list-pager-previous"
            :disabled="props.isDisabled || props.currentPage <= 1"
            @click="emit('change-page', props.currentPage - 1)"
        >
            {{ t('i18n.components.data_grid.list_pager.back') }}
        </Button>
        <span class="text-sm">
            {{ t('i18n.components.data_grid.list_pager.page') }}
            {{ props.currentPage }}
            {{ t('i18n.components.data_grid.list_pager.of') }}
            {{ props.lastPage }}
        </span>
        <Button
            variant="outline"
            size="sm"
            data-testid="list-pager-next"
            :disabled="props.isDisabled || props.currentPage >= props.lastPage"
            @click="emit('change-page', props.currentPage + 1)"
        >
            {{ t('i18n.components.data_grid.list_pager.continue') }}
        </Button>
    </div>
</template>
