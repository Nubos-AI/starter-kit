<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    {
        value: 'light',
        Icon: Sun,
        label: t('i18n.components.appearance_tabs.light'),
    },
    {
        value: 'dark',
        Icon: Moon,
        label: t('i18n.components.appearance_tabs.dark'),
    },
    {
        value: 'system',
        Icon: Monitor,
        label: t('i18n.components.appearance_tabs.system'),
    },
] as const;
</script>

<template>
    <div class="inline-flex gap-1 rounded-lg bg-neutral p-1">
        <Button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            variant="ghost"
            size="sm"
            :aria-pressed="appearance === value"
            :class="[
                'gap-1.5 px-3.5',
                appearance === value
                    ? 'bg-surface-raised text-foreground shadow-raised hover:bg-surface-raised'
                    : 'text-subtle hover:bg-neutral-subtle-hovered hover:text-foreground',
            ]"
            @click="updateAppearance(value)"
        >
            <component :is="Icon" class="size-4" />
            <span class="text-sm">{{ label }}</span>
        </Button>
    </div>
</template>
