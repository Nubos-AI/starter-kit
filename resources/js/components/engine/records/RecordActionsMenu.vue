<script setup lang="ts">
import { Copy, MoreHorizontal, Merge, PanelRight, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const MERGE_REASON = t(
    'i18n.components.engine.records.record_actions_menu.you_do_not_have_permission_to_merge_this_record',
);

const TRASHED_REASON = t(
    'i18n.components.engine.records.record_actions_menu.the_record_is_deleted',
);

const DELETE_REASON = t(
    'i18n.components.engine.records.record_actions_menu.you_do_not_have_permission_to_delete_this_record',
);

const props = withDefaults(
    defineProps<{
        trashed?: boolean;
        canDelete?: boolean;
        canMerge?: boolean;
        canManagePanels?: boolean;
    }>(),
    {
        trashed: false,
        canDelete: true,
        canMerge: true,
        canManagePanels: true,
    },
);

const emit = defineEmits<{
    'manage-panels': [];
    duplicate: [];
    merge: [];
    delete: [];
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                size="icon"
                :aria-label="
                    t(
                        'i18n.components.engine.records.record_actions_menu.more_actions',
                    )
                "
                data-record-actions-trigger
            >
                <MoreHorizontal class="size-4" aria-hidden="true" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            extension-point="menus.record-actions-menu"
            :extension-context="$props"
            align="end"
            class="w-56"
        >
            <DropdownMenuItem
                data-record-action="duplicate"
                :disabled="props.trashed"
                :title="props.trashed ? TRASHED_REASON : undefined"
                @select="emit('duplicate')"
            >
                <Copy class="size-4" aria-hidden="true" />
                {{
                    t(
                        'i18n.components.engine.records.record_actions_menu.duplicate_record',
                    )
                }}
            </DropdownMenuItem>

            <DropdownMenuItem
                data-record-action="merge"
                :disabled="props.trashed || !props.canMerge"
                :title="
                    props.trashed
                        ? TRASHED_REASON
                        : props.canMerge
                          ? undefined
                          : MERGE_REASON
                "
                @select="emit('merge')"
            >
                <Merge class="size-4" aria-hidden="true" />
                {{
                    t(
                        'i18n.components.engine.records.record_actions_menu.merge',
                    )
                }}
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem
                data-record-action="delete"
                variant="destructive"
                :disabled="props.trashed || !props.canDelete"
                :title="
                    props.trashed
                        ? TRASHED_REASON
                        : props.canDelete
                          ? undefined
                          : DELETE_REASON
                "
                @select="emit('delete')"
            >
                <Trash2 class="size-4" aria-hidden="true" />
                {{
                    t(
                        'i18n.components.engine.records.record_actions_menu.delete',
                    )
                }}
            </DropdownMenuItem>

            <template v-if="props.canManagePanels">
                <DropdownMenuSeparator />

                <DropdownMenuItem
                    data-record-action="panels"
                    @select="emit('manage-panels')"
                >
                    <PanelRight class="size-4" aria-hidden="true" />
                    {{
                        t(
                            'i18n.components.engine.records.record_actions_menu.manage_sidebar',
                        )
                    }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
