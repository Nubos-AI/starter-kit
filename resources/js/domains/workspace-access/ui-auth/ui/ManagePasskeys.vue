<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import { useI18n } from '@/shared/composables/useI18n';
import type { Passkey } from '@/shared/types/auth';
import CardSection from '@/shared/ui/CardSection.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import PasskeyItem from './PasskeyItem.vue';
import PasskeyRegister from './PasskeyRegister.vue';

const { t } = useI18n();

export type Props = {
  canManagePasskeys?: boolean;
  passkeys?: Passkey[];
};

withDefaults(defineProps<Props>(), {
  canManagePasskeys: false,
  passkeys: () => [],
});

const handleDelete = (id: number, onError: () => void) => {
  router.delete(destroy.url(id), {
    preserveScroll: true,
    onError,
  });
};

const handleRegisterSuccess = () => {
  router.reload();
};
</script>

<template>
  <CardSection
    v-if="canManagePasskeys"
    :title="t('i18n.components.manage_passkeys.passkeys')"
    :description="t('i18n.components.manage_passkeys.manage_your_passkeys_for_passwordless_sign_in')"
    data-manage-passkeys
  >
    <div class="overflow-hidden rounded-lg border border-border">
      <template v-if="passkeys.length">
        <PasskeyItem v-for="passkey in passkeys" :key="passkey.id" :passkey="passkey" @remove="handleDelete" />
      </template>

      <div v-else class="flex flex-col items-center gap-3 p-8 text-center">
        <EmptyState
          :icon="KeyRound"
          heading-level="h3"
          :title="t('i18n.components.manage_passkeys.no_passkeys_yet')"
          :description="t('i18n.components.manage_passkeys.create_a_passkey_to_sign_in_without_a_password')"
        />
      </div>
    </div>

    <PasskeyRegister @success="handleRegisterSuccess" />
  </CardSection>
</template>
