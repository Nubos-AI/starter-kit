<script setup lang="ts">
import { Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = defineProps<{
    count: number;
    deleteLabel?: string;
}>();

const emit = defineEmits<{
    delete: [];
    clear: [];
}>();

const confirming = ref<boolean>(false);

const question = computed<string>(() =>
    props.count === 1
        ? t(
              'i18n.components.data_grid.selection_bulk_bar.permanently_delete_this_entry',
          )
        : t(
              'i18n.components.data_grid.selection_bulk_bar.permanently_delete_these_entries',
              { value1: props.count },
          ),
);

function confirm(): void {
    confirming.value = false;
    emit('delete');
}
</script>

<template>
    <div
        v-if="count > 0"
        class="flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2"
        role="toolbar"
        :aria-label="
            t('i18n.components.data_grid.selection_bulk_bar.bulk_actions')
        "
    >
        <Badge variant="secondary"
            >{{ count }}
            {{
                t('i18n.components.data_grid.selection_bulk_bar.selected')
            }}</Badge
        >

        <template v-if="!confirming">
            <Button
                variant="destructive"
                size="sm"
                data-testid="bulk-delete"
                @click="confirming = true"
            >
                <Trash2 class="mr-1 size-4" />
                {{
                    deleteLabel ??
                    t('i18n.components.data_grid.selection_bulk_bar.delete')
                }}
            </Button>

            <Button
                variant="ghost"
                size="sm"
                data-testid="bulk-clear"
                @click="emit('clear')"
            >
                <X class="mr-1 size-4" />
                {{ t('i18n.components.data_grid.selection_bulk_bar.clear') }}
            </Button>
        </template>

        <template v-else>
            <span class="text-sm" data-testid="bulk-delete-question">
                {{ question }}
                {{
                    t(
                        'i18n.components.data_grid.selection_bulk_bar.this_cannot_be_undone',
                    )
                }}
            </span>

            <Button
                variant="destructive"
                size="sm"
                data-testid="bulk-delete-confirm"
                @click="confirm"
            >
                <Trash2 class="mr-1 size-4" />
                {{
                    t(
                        'i18n.components.data_grid.selection_bulk_bar.delete_permanently',
                    )
                }}
            </Button>

            <Button
                variant="ghost"
                size="sm"
                data-testid="bulk-delete-cancel"
                @click="confirming = false"
            >
                {{ t('i18n.components.data_grid.selection_bulk_bar.cancel') }}
            </Button>
        </template>
    </div>
</template>
