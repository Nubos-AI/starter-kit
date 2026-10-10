<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    reload: [];
    close: [];
}>();

const onOpenChange = (value: boolean): void => {
    if (!value) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{
                        t(
                            'i18n.components.engine.conflict_dialog.this_record_has_been_changed',
                        )
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'i18n.components.engine.conflict_dialog.another_user_has_changed_this_record_the_current_values',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="emit('close')">
                    {{ t('i18n.components.engine.conflict_dialog.close') }}
                </Button>
                <Button @click="emit('reload')">
                    {{
                        t(
                            'i18n.components.engine.conflict_dialog.load_current_values',
                        )
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
