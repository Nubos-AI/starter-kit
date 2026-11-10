<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import type { SegmentSummary } from '@/composables/useSegments';
import { useSegmentShares } from '@/composables/useSegmentShares';
import type {
    GranteeOption,
    GranteeType,
    SegmentShare,
} from '@/composables/useSegmentShares';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    open: boolean;
    segment: SegmentSummary;
    granteeOptions: Record<GranteeType, GranteeOption[]>;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

interface GranteeTypeOption {
    value: GranteeType;
    label: string;
}

const granteeTypeOptions: GranteeTypeOption[] = [
    {
        value: 'team',
        label: t('i18n.components.engine.segment.segment_share_dialog.team'),
    },
    {
        value: 'role',
        label: t('i18n.components.engine.segment.segment_share_dialog.role'),
    },
    {
        value: 'user',
        label: t('i18n.components.engine.segment.segment_share_dialog.user'),
    },
];

const { shares, loading, error, load, create, revoke } = useSegmentShares(
    props.segment.id,
);

const granteeType = ref<GranteeType>('team');
const granteeId = ref<string>('');
const canEdit = ref<boolean>(false);
const submitting = ref<boolean>(false);

const currentOptions = computed<GranteeOption[]>(
    () => props.granteeOptions[granteeType.value] ?? [],
);

const granteeChoices = computed<SelectOption[]>(() =>
    currentOptions.value.map((option) => ({
        value: option.id,
        label: option.label,
        avatar:
            granteeType.value === 'user' ? { name: option.label } : undefined,
    })),
);

const createDisabled = computed<boolean>(
    () => submitting.value || granteeId.value === '',
);

function firstOptionId(type: GranteeType): string {
    return props.granteeOptions[type]?.[0]?.id ?? '';
}

function granteeLabelFor(share: SegmentShare): string {
    const option = props.granteeOptions[share.grantee_type]?.find(
        (candidate) => candidate.id === share.grantee_id,
    );

    return option?.label ?? share.grantee_id;
}

function selectGranteeType(value: GranteeType): void {
    if (granteeType.value === value) {
        return;
    }

    granteeType.value = value;
    granteeId.value = firstOptionId(value);
}

function resetForm(): void {
    granteeType.value = 'team';
    granteeId.value = firstOptionId('team');
    canEdit.value = false;
}

async function onCreate(): Promise<void> {
    if (createDisabled.value) {
        return;
    }

    submitting.value = true;

    try {
        const created = await create({
            grantee_type: granteeType.value,
            grantee_id: granteeId.value,
            can_edit: canEdit.value,
        });

        if (created !== null) {
            canEdit.value = false;
        }
    } finally {
        submitting.value = false;
    }
}

async function onRevoke(shareId: string): Promise<void> {
    await revoke(shareId);
}

function onOpenChange(value: boolean): void {
    if (!value) {
        emit('update:open', false);
    }
}

const granteeTypeLabel = computed<string>(
    () =>
        granteeTypeOptions.find((option) => option.value === granteeType.value)
            ?.label ?? '',
);

watch(
    () => props.open,
    (value) => {
        if (value && props.segment.is_owner) {
            resetForm();
            void load();
        }
    },
    { immediate: true },
);
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    t(
                        'i18n.components.engine.segment.segment_share_dialog.share_segment',
                    )
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'i18n.components.engine.segment.segment_share_dialog.share',
                        )
                    }}{{ segment.name
                    }}{{
                        t(
                            'i18n.components.engine.segment.segment_share_dialog.with_a_team_role_or_individual_users',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <template v-if="segment.is_owner">
                <div class="flex flex-col gap-4">
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium">
                            {{
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.recipient_type',
                                )
                            }}
                        </legend>
                        <div class="flex gap-2">
                            <Button
                                v-for="option in granteeTypeOptions"
                                :key="option.value"
                                type="button"
                                size="sm"
                                :variant="
                                    granteeType === option.value
                                        ? 'default'
                                        : 'outline'
                                "
                                :aria-pressed="granteeType === option.value"
                                :data-grantee-type-option="option.value"
                                class="flex-1"
                                @click="selectGranteeType(option.value)"
                            >
                                {{ option.label }}
                            </Button>
                        </div>
                    </fieldset>

                    <div class="flex flex-col gap-2">
                        <Label
                            >{{ granteeTypeLabel }}
                            {{
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.select',
                                )
                            }}</Label
                        >
                        <Combobox
                            v-model="granteeId"
                            :options="granteeChoices"
                            :searchable="granteeChoices.length > 8"
                            :placeholder="
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.select_recipient',
                                )
                            "
                            :search-placeholder="
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.search_recipients',
                                )
                            "
                            :aria-label="
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.select_2',
                                    { value1: granteeTypeLabel },
                                )
                            "
                            data-grantee-select
                        />
                    </div>

                    <label
                        class="flex items-center gap-2 text-sm"
                        for="segment-share-can-edit"
                    >
                        <Checkbox
                            id="segment-share-can-edit"
                            data-can-edit-toggle
                            :model-value="canEdit"
                            @update:model-value="canEdit = Boolean($event)"
                        />
                        <span>{{
                            t(
                                'i18n.components.engine.segment.segment_share_dialog.allow_editing_instead_of_viewing_only',
                            )
                        }}</span>
                    </label>

                    <div class="flex justify-end">
                        <Button
                            data-share-create
                            size="sm"
                            :disabled="createDisabled"
                            @click="onCreate"
                        >
                            {{
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.share_2',
                                )
                            }}
                        </Button>
                    </div>

                    <div class="flex flex-col gap-2">
                        <span class="text-sm font-medium">
                            {{
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.existing_shares',
                                )
                            }}
                        </span>

                        <Skeleton v-if="loading" class="h-9 w-full" />
                        <p
                            v-else-if="shares.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                t(
                                    'i18n.components.engine.segment.segment_share_dialog.this_segment_has_not_been_shared_yet',
                                )
                            }}
                        </p>
                        <ul v-else class="flex flex-col gap-2">
                            <li
                                v-for="share in shares"
                                :key="share.id"
                                data-grant-entry
                                :data-grant-id="share.id"
                                :data-grant-can-edit="
                                    share.can_edit ? 'true' : 'false'
                                "
                                class="flex items-center justify-between gap-2 rounded-md border px-3 py-2"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="text-sm">
                                        {{ granteeLabelFor(share) }}
                                    </span>
                                    <Badge
                                        :variant="
                                            share.can_edit
                                                ? 'default'
                                                : 'secondary'
                                        "
                                    >
                                        {{
                                            share.can_edit
                                                ? t(
                                                      'i18n.components.engine.segment.segment_share_dialog.edit',
                                                  )
                                                : t(
                                                      'i18n.components.engine.segment.segment_share_dialog.view',
                                                  )
                                        }}
                                    </Badge>
                                </span>
                                <Button
                                    data-grant-revoke
                                    size="sm"
                                    variant="ghost"
                                    @click="onRevoke(share.id)"
                                >
                                    {{
                                        t(
                                            'i18n.components.engine.segment.segment_share_dialog.revoke',
                                        )
                                    }}
                                </Button>
                            </li>
                        </ul>
                    </div>

                    <p
                        v-if="error !== null"
                        data-share-error
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ error }}
                    </p>
                </div>
            </template>

            <p v-else class="text-sm text-muted-foreground">
                {{
                    t(
                        'i18n.components.engine.segment.segment_share_dialog.only_the_creator_can_share_this_segment',
                    )
                }}
            </p>

            <DialogFooter>
                <Button variant="outline" @click="onOpenChange(false)">
                    {{
                        t(
                            'i18n.components.engine.segment.segment_share_dialog.close',
                        )
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
