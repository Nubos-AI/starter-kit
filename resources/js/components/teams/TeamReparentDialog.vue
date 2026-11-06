<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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

const description = computed<string>(() => {
    const subjectName =
        props.subject === null
            ? t('i18n.components.teams.team_reparent_dialog.this_team')
            : props.subject.name;

    return `${t('i18n.components.teams.team_reparent_dialog.select_the_new_parent_team_for')} ${subjectName}.`;
});

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
    return isSelected(parentTeamId) ? 'border-selected bg-selected' : '';
}

function confirm(): void {
    if (!canConfirm.value) {
        return;
    }

    emit('submit', selectedParentId.value);
}
</script>

<template>
    <ConfirmDialog
        :open="props.open"
        :title="t('i18n.components.teams.team_reparent_dialog.move_team')"
        :description="description"
        :confirm-label="
            t('i18n.components.teams.team_reparent_dialog.move_team')
        "
        :cancel-label="t('i18n.components.teams.team_reparent_dialog.cancel')"
        :pending="props.processing"
        :confirm-disabled="!canConfirm"
        :confirm-attrs="{ 'data-reparent-confirm': '' }"
        :cancel-attrs="{ 'data-reparent-cancel': '' }"
        @update:open="(value) => emit('update:open', value)"
        @confirm="confirm"
    >
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
    </ConfirmDialog>
</template>
