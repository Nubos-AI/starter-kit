<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import type { TeamTreeNode } from '@/types/teams';

const { t } = useI18n();

const props = defineProps<{
    open: boolean;
    subject: TeamTreeNode | null;
    nodes: TeamTreeNode[];
    errorMessage: string | null;
    processing: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    submit: [parentTeamId: string | null];
}>();

const selectedParentId = ref<string | null>(null);
const hasSelection = ref<boolean>(false);

const candidates = computed<TeamTreeNode[]>(() => {
    const subject = props.subject;

    if (subject === null) {
        return [];
    }

    const excluded = new Set<string>([
        subject.id,
        ...subject.descendantTeamIds,
    ]);

    return props.nodes.filter((node) => !excluded.has(node.id));
});

const affectedCount = computed<number>(() =>
    props.subject === null ? 0 : props.subject.descendantTeamIds.length + 1,
);

const canConfirm = computed<boolean>(
    () => hasSelection.value && !props.processing,
);

watch(
    () => [props.open, props.subject?.id],
    () => {
        selectedParentId.value = null;
        hasSelection.value = false;
    },
);

function select(parentTeamId: string | null): void {
    selectedParentId.value = parentTeamId;
    hasSelection.value = true;
}

function isSelected(parentTeamId: string | null): boolean {
    return hasSelection.value && selectedParentId.value === parentTeamId;
}

function optionClass(parentTeamId: string | null): string {
    return isSelected(parentTeamId)
        ? 'border-primary bg-primary/10'
        : 'border-border hover:bg-muted';
}

function close(): void {
    emit('update:open', false);
}

function confirm(): void {
    if (!canConfirm.value) {
        return;
    }

    emit('submit', selectedParentId.value);
}
</script>

<template>
    <Dialog
        :open="props.open"
        @update:open="(value) => (value ? undefined : close())"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    t('i18n.components.teams.team_reparent_dialog.move_team')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'i18n.components.teams.team_reparent_dialog.select_the_new_parent_team_for',
                        )
                    }}
                    {{
                        props.subject === null
                            ? t(
                                  'i18n.components.teams.team_reparent_dialog.this_team',
                              )
                            : props.subject.name
                    }}.
                </DialogDescription>
            </DialogHeader>

            <p
                data-visibility-warning
                class="rounded-md border border-warning-subtle bg-warning p-3 text-sm"
            >
                {{
                    t(
                        'i18n.components.teams.team_reparent_dialog.moving_this_team_shifts_record_visibility_for',
                    )
                }}
                {{ affectedCount }}
                {{
                    t(
                        'i18n.components.teams.team_reparent_dialog.team_s_because_every_role_that_reaches_down_the',
                    )
                }}
            </p>

            <div class="flex max-h-72 flex-col gap-1 overflow-y-auto">
                <Button
                    data-root-option
                    type="button"
                    variant="outline"
                    class="h-auto justify-start px-3 py-2 text-left font-normal"
                    :class="optionClass(null)"
                    @click="select(null)"
                >
                    {{
                        t(
                            'i18n.components.teams.team_reparent_dialog.make_it_a_root_team',
                        )
                    }}
                </Button>

                <Button
                    v-for="candidate in candidates"
                    :key="candidate.id"
                    :data-candidate-id="candidate.id"
                    type="button"
                    variant="outline"
                    class="h-auto justify-start px-3 py-2 text-left font-normal"
                    :class="optionClass(candidate.id)"
                    @click="select(candidate.id)"
                >
                    {{ candidate.name }}
                </Button>

                <p
                    v-if="candidates.length === 0"
                    class="px-3 py-2 text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'i18n.components.teams.team_reparent_dialog.there_is_no_other_team_this_one_could_move',
                        )
                    }}
                </p>
            </div>

            <InputError
                v-if="props.errorMessage"
                data-reparent-error
                :message="props.errorMessage"
            />

            <DialogFooter>
                <Button
                    data-reparent-cancel
                    variant="outline"
                    :disabled="props.processing"
                    @click="close"
                >
                    {{ t('i18n.components.teams.team_reparent_dialog.cancel') }}
                </Button>
                <Button
                    data-reparent-confirm
                    :disabled="!canConfirm"
                    @click="confirm"
                >
                    {{
                        t(
                            'i18n.components.teams.team_reparent_dialog.move_team',
                        )
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
