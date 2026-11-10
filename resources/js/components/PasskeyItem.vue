<script setup lang="ts">
import { KeyRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { DeleteButton } from '@/components/ui/icon-action-button';
import { useI18n } from '@/composables/useI18n';
import type { Passkey } from '@/types/auth';

const { t } = useI18n();

const props = defineProps<{
    passkey: Passkey;
}>();

const emit = defineEmits<{
    remove: [id: number, onError: () => void];
}>();

const confirmOpen = ref<boolean>(false);
const isDeleting = ref<boolean>(false);

const description = computed<string>(
    () =>
        `${t('i18n.components.passkey_item.are_you_sure_you_want_to_remove_the_passkey')}${props.passkey.name}${t('i18n.components.passkey_item.you_will_no_longer_be_able_to_use_it')}`,
);

function handleDelete(): void {
    isDeleting.value = true;
    emit('remove', props.passkey.id, () => {
        isDeleting.value = false;
    });
}
</script>

<template>
    <div class="flex items-center justify-between border-b p-4 last:border-b-0">
        <div class="flex items-center gap-4">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted"
            >
                <KeyRound class="size-5 text-muted-foreground" />
            </div>
            <div class="grid gap-1">
                <div class="flex items-center gap-2">
                    <p class="font-medium">{{ passkey.name }}</p>
                    <span
                        v-if="passkey.authenticator"
                        class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground uppercase ring-1 ring-border ring-inset"
                    >
                        {{ passkey.authenticator }}
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ t('i18n.components.passkey_item.created') }}
                    {{ passkey.created_at_diff }}
                    <template v-if="passkey.last_used_at_diff">
                        <span class="mx-1 text-subtlest">/</span>
                        {{ t('i18n.components.passkey_item.last_used') }}
                        {{ passkey.last_used_at_diff }}
                    </template>
                </p>
            </div>
        </div>

        <DeleteButton
            :label="t('i18n.components.passkey_item.remove')"
            @click="confirmOpen = true"
        />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('i18n.components.passkey_item.remove_passkey')"
            :description="description"
            :confirm-label="
                isDeleting
                    ? t('i18n.components.passkey_item.removing')
                    : t('i18n.components.passkey_item.remove_passkey')
            "
            :cancel-label="t('i18n.components.passkey_item.cancel')"
            variant="destructive"
            :pending="isDeleting"
            @confirm="handleDelete"
        />
    </div>
</template>
