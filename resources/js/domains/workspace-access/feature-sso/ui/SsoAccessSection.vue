<script setup lang="ts">
import { useI18n } from '@/shared/composables/useI18n';
import type { SelectOption } from '@/shared/types/ui';
import CardSection from '@/shared/ui/CardSection.vue';
import FormField from '@/shared/ui/FormField.vue';
import FormSwitch from '@/shared/ui/FormSwitch.vue';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Textarea } from '@/shared/ui/primitives/textarea';
import type { SsoDomainProof } from '../types/sso';
import SsoDomainProofList from './SsoDomainProofList.vue';

defineProps<{
  roleOptions: SelectOption[];
  domainProofs: SsoDomainProof[];
  verifiedDomains: string[];
  isVerifyingDomain: boolean;
  errors: Partial<Record<string, string>>;
  canEnforce: boolean;
  enforceReason: string | null;
}>();

const domains = defineModel<string>('domains', { required: true });
const isEnforced = defineModel<boolean>('isEnforced', { required: true });
const isJitEnabled = defineModel<boolean>('isJitEnabled', { required: true });
const defaultRoleId = defineModel<string | null>('defaultRoleId', {
  required: true,
});

const emit = defineEmits<{ 'verify-domain': [domain: string] }>();

const { t } = useI18n();
</script>

<template>
  <CardSection
    :title="t('i18n.components.sso.sso_access_card.title')"
    :description="t('i18n.components.sso.sso_access_card.description')"
  >
    <FormField
      for="allowed_domains"
      :label="t('i18n.components.sso.sso_access_card.allowed_domains')"
      :hint="t('i18n.components.sso.sso_access_card.allowed_domains_hint')"
      :error="errors.domain ?? errors.allowed_domains"
      class="sm:max-w-sm"
    >
      <Textarea id="allowed_domains" v-model="domains" rows="3" />
    </FormField>

    <SsoDomainProofList
      v-if="domainProofs.length > 0"
      :proofs="domainProofs"
      :verified-domains="verifiedDomains"
      :is-verifying="isVerifyingDomain"
      @verify-domain="emit('verify-domain', $event)"
    />

    <div class="grid gap-1">
      <FormSwitch
        id="is_enforced"
        v-model="isEnforced"
        :label="t('i18n.components.sso.sso_access_card.is_enforced')"
        :description="t('i18n.components.sso.sso_access_card.enforced_hint')"
        :disabled="!canEnforce"
        :error="errors.is_enforced"
      />
      <p v-if="!canEnforce && enforceReason !== null" class="text-xs text-muted-foreground" data-sso-enforce-reason>
        {{ enforceReason }}
      </p>
    </div>

    <FormSwitch
      id="is_jit_enabled"
      v-model="isJitEnabled"
      :label="t('i18n.components.sso.sso_access_card.is_jit_enabled')"
      :description="t('i18n.components.sso.sso_access_card.jit_hint')"
      :error="errors.is_jit_enabled"
    />

    <FormField
      for="default_role_id"
      :label="t('i18n.components.sso.sso_access_card.default_role')"
      :error="errors.default_role_id"
      class="sm:max-w-sm"
    >
      <Combobox
        id="default_role_id"
        v-model="defaultRoleId"
        :options="roleOptions"
        :searchable="roleOptions.length > 8"
        :disabled="!isJitEnabled"
        :placeholder="t('i18n.components.sso.sso_access_card.default_role_placeholder')"
      />
    </FormField>
  </CardSection>
</template>
