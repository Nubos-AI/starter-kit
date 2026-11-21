<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Eye, EyeOff, RefreshCw } from '@lucide/vue';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import { useTwoFactorAuth } from '@/domains/workspace-access/data';
import { regenerateRecoveryCodes } from '@/routes/two-factor';
import { useI18n } from '@/shared/composables/useI18n';
import AlertError from '@/shared/ui/AlertError.vue';
import CardSection from '@/shared/ui/CardSection.vue';
import { Button } from '@/shared/ui/primitives/button';
import SkeletonList from '@/shared/ui/SkeletonList.vue';

const { t } = useI18n();

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const toggleRecoveryCodesVisibility = async () => {
  if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
    await fetchRecoveryCodes();
  }

  isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

  if (isRecoveryCodesVisible.value) {
    await nextTick();
    recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
  }
};

onMounted(async () => {
  if (!recoveryCodesList.value.length) {
    await fetchRecoveryCodes();
  }
});
</script>

<template>
  <CardSection
    :title="t('i18n.components.two_factor_recovery_codes.recovery_codes')"
    :description="t('i18n.components.two_factor_recovery_codes.recovery_codes_let_you_regain_access_if_you_lose')"
    data-two-factor-recovery-codes
  >
    <div class="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between">
      <Button @click="toggleRecoveryCodesVisibility" class="w-fit">
        <component :is="isRecoveryCodesVisible ? EyeOff : Eye" class="size-4" />
        {{ t('i18n.components.two_factor_recovery_codes.recovery_codes') }}
        {{ isRecoveryCodesVisible ? 'verbergen' : 'anzeigen' }}
      </Button>

      <Form
        v-if="isRecoveryCodesVisible && recoveryCodesList.length"
        v-bind="regenerateRecoveryCodes.form()"
        method="post"
        :options="{ preserveScroll: true }"
        @success="fetchRecoveryCodes"
        #default="{ processing }"
      >
        <Button variant="secondary" type="submit" :disabled="processing">
          <RefreshCw />
          {{ t('i18n.components.two_factor_recovery_codes.generate_new_codes') }}
        </Button>
      </Form>
    </div>
    <div
      :class="[
        'relative overflow-hidden transition-all duration-300',
        isRecoveryCodesVisible ? 'h-auto opacity-100' : 'h-0 opacity-0',
      ]"
    >
      <div v-if="errors?.length" class="mt-6">
        <AlertError :errors="errors" />
      </div>
      <div v-else class="mt-3 space-y-3">
        <div ref="recoveryCodeSectionRef" class="grid gap-1 rounded-lg bg-muted p-4 font-mono text-sm">
          <SkeletonList v-if="!recoveryCodesList.length" :count="8" size="sm" />
          <div v-else v-for="(code, index) in recoveryCodesList" :key="index">
            {{ code }}
          </div>
        </div>
        <p class="text-xs text-muted-foreground select-none">
          {{ t('i18n.components.two_factor_recovery_codes.each_recovery_code_can_be_used_only_once_and') }}
          <span class="font-bold">{{ t('i18n.components.two_factor_recovery_codes.generate_new_codes') }}</span>
          {{ t('i18n.components.two_factor_recovery_codes.button_above') }}
        </p>
      </div>
    </div>
  </CardSection>
</template>
