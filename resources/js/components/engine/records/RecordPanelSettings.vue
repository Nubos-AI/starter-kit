<script setup lang="ts">
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { useHiddenSections } from '@/composables/useHiddenSections';
import { useI18n } from '@/composables/useI18n';
import type { RecordPanel } from '@/lib/recordPanels';

const { t } = useI18n();

const SHEET_TITLE = t(
    'i18n.components.engine.records.record_panel_settings.manage_sidebar',
);

const SHEET_DESCRIPTION = t(
    'i18n.components.engine.records.record_panel_settings.choose_which_panels_to_show_on_this_record_page',
);

const props = defineProps<{
    open: boolean;
    panels: RecordPanel[];
    objectTypeId: string | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const { isVisible, setVisible } = useHiddenSections(props.objectTypeId);

function onOpenChange(next: boolean): void {
    if (!next) {
        emit('close');
    }
}
</script>

<template>
    <Sheet :open="props.open" @update:open="onOpenChange">
        <SheetContent side="right" class="w-full sm:max-w-sm">
            <SheetHeader>
                <SheetTitle>{{ SHEET_TITLE }}</SheetTitle>
                <SheetDescription>{{ SHEET_DESCRIPTION }}</SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-4 overflow-y-auto px-4 pb-4">
                <div
                    v-for="panel in props.panels"
                    :key="panel.id"
                    class="flex items-center justify-between gap-4"
                >
                    <Label :for="`panel-${panel.id}`">{{ panel.label }}</Label>

                    <Switch
                        :id="`panel-${panel.id}`"
                        :data-panel-switch="panel.id"
                        :model-value="isVisible(panel.id)"
                        @update:model-value="setVisible(panel.id, $event)"
                    />
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
