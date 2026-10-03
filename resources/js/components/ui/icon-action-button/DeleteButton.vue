<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { Trash2 } from '@lucide/vue';
import IconActionButton from './IconActionButton.vue';

const { t } = useI18n();

withDefaults(
    defineProps<{
        label?: string;
        href?: string;
        type?: 'button' | 'submit';
        disabled?: boolean;
        testId?: string;
    }>(),
    {
        label: undefined,
        type: 'button',
    },
);

const emit = defineEmits<{
    click: [];
}>();
</script>

<template>
    <IconActionButton
        :icon="Trash2"
        variant="destructive"
        :label="(label ?? t('i18n.components.ui.icon_action_button.delete_button.delete'))"
        :href="href"
        :type="type"
        :disabled="disabled"
        :test-id="testId"
        @click="emit('click')"
    />
</template>
