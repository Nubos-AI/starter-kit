<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import type { ButtonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

withDefaults(
    defineProps<{
        label?: string;
        showIcon?: boolean;
        href?: string;
        type?: 'button' | 'submit';
        disabled?: boolean;
        size?: ButtonVariants['size'];
        only?: string[];
    }>(),
    {
        label: undefined,
        showIcon: true,
        type: 'button',
    },
);

const emit = defineEmits<{
    click: [];
}>();
</script>

<template>
    <Button
        v-if="href"
        variant="create"
        :size="size"
        as-child
        data-create-button
    >
        <Link :href="href" :only="only">
            <Plus v-if="showIcon" class="size-4" />
            {{ label ?? t('i18n.components.create_button.create') }}
        </Link>
    </Button>

    <Button
        v-else
        variant="create"
        :size="size"
        :type="type"
        :disabled="disabled"
        data-create-button
        @click="emit('click')"
    >
        <Plus v-if="showIcon" class="size-4" />
        {{ label ?? t('i18n.components.create_button.create') }}
    </Button>
</template>
