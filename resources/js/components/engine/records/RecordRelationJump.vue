<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { recordRouteKey } from '@/lib/recordRouteKey';
import type { RelationEntry } from '@/types/relations';

defineProps<{
    entries: RelationEntry[];
}>();

const { t } = useI18n();

const label = t('i18n.components.engine.records.record_relations.open_record');

function hrefOf(entry: RelationEntry): string {
    return RecordsController.show.url({
        record: recordRouteKey({
            id: entry.recordId,
            recordNumber: entry.recordNumber,
        }),
    });
}
</script>

<template>
    <Button
        v-if="entries.length === 1"
        as-child
        variant="outline"
        size="icon"
        class="shrink-0"
    >
        <Link
            :href="hrefOf(entries[0])"
            :aria-label="label"
            :title="label"
            data-relation-open
        >
            <ArrowUpRight class="size-4" aria-hidden="true" />
        </Link>
    </Button>

    <DropdownMenu v-else-if="entries.length > 1">
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="outline"
                size="icon"
                class="shrink-0"
                :aria-label="label"
                :title="label"
                data-relation-open
            >
                <ArrowUpRight class="size-4" aria-hidden="true" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="max-w-80">
            <DropdownMenuItem
                v-for="entry in entries"
                :key="entry.linkId"
                as-child
            >
                <Link :href="hrefOf(entry)" data-relation-open-entry>
                    <span class="truncate">{{ entry.label }}</span>
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <Button
        v-else
        type="button"
        variant="outline"
        size="icon"
        class="shrink-0"
        disabled
        :aria-label="label"
        :title="
            t('i18n.components.engine.records.record_relations.nothing_linked')
        "
        data-relation-open
    >
        <ArrowUpRight class="size-4" aria-hidden="true" />
    </Button>
</template>
