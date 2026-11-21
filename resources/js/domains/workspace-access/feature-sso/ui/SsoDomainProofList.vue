<script setup lang="ts">
import { useI18n } from '@/shared/composables/useI18n';
import { SSO_DOMAIN_VERIFICATION } from '@/shared/lib/statusMaps';
import DetailItem from '@/shared/ui/DetailItem.vue';
import DetailList from '@/shared/ui/DetailList.vue';
import { Button } from '@/shared/ui/primitives/button';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';
import SubsectionHeading from '@/shared/ui/SubsectionHeading.vue';
import type { SsoDomainProof } from '../types/sso';

const props = defineProps<{
  proofs: SsoDomainProof[];
  verifiedDomains: string[];
  isVerifying: boolean;
}>();

const emit = defineEmits<{ 'verify-domain': [domain: string] }>();

const { t } = useI18n();

function statusOf(domain: string): 'verified' | 'unverified' {
  return props.verifiedDomains.includes(domain) ? 'verified' : 'unverified';
}
</script>

<template>
  <div class="grid gap-3" data-sso-domain-proofs>
    <SubsectionHeading
      :title="t('i18n.components.sso.sso_access_card.domain_proofs_title')"
      :description="t('i18n.components.sso.sso_access_card.domain_proofs_hint')"
    />
    <div
      v-for="proof in proofs"
      :key="proof.domain"
      class="grid gap-2 rounded-md border p-3"
      :data-sso-domain-proof="proof.domain"
    >
      <div class="flex items-center gap-3">
        <span class="text-sm font-medium">{{ proof.domain }}</span>
        <StatusBadge
          :map="SSO_DOMAIN_VERIFICATION"
          :status="statusOf(proof.domain)"
          :data-sso-domain-status="statusOf(proof.domain)"
        />
      </div>
      <template v-if="statusOf(proof.domain) === 'unverified'">
        <DetailList compact>
          <DetailItem :label="t('i18n.components.sso.sso_access_card.record_name')">
            <span class="font-mono break-all" data-sso-domain-record-name>{{ proof.recordName }}</span>
          </DetailItem>
          <DetailItem :label="t('i18n.components.sso.sso_access_card.record_value')">
            <span class="font-mono break-all" data-sso-domain-record-value>{{ proof.recordValue }}</span>
          </DetailItem>
        </DetailList>
        <div>
          <Button
            type="button"
            variant="outline"
            :disabled="isVerifying"
            data-sso-domain-verify
            @click="emit('verify-domain', proof.domain)"
          >
            {{ t('i18n.components.sso.sso_access_card.verify') }}
          </Button>
        </div>
      </template>
    </div>
  </div>
</template>
