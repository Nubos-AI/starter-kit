<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import TrashController from '@/actions/App/Http/Controllers/Engine/TrashController';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        :aria-label="t('i18n.components.trash.trash_button.trash')"
        as-child
        data-trash-button
    >
        <Link :href="TrashController.index.url()">
            <Trash2 />
        </Link>
    </Button>
</template>
