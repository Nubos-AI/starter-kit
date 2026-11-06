<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { useI18n } from '@/composables/useI18n';
import { SSO_DOMAIN_VERIFICATION } from '@/lib/statusMaps';
import type { SsoDomainProof } from '@/types/sso';

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
        <div class="grid gap-1">
            <span class="text-sm font-medium">{{
                t('i18n.components.sso.sso_access_card.domain_proofs_title')
            }}</span>
            <p class="text-xs text-muted-foreground">
                {{
                    t('i18n.components.sso.sso_access_card.domain_proofs_hint')
                }}
            </p>
        </div>
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
                <dl class="grid gap-1 text-xs">
                    <dt class="text-muted-foreground">
                        {{
                            t('i18n.components.sso.sso_access_card.record_name')
                        }}
                    </dt>
                    <dd class="font-mono break-all" data-sso-domain-record-name>
                        {{ proof.recordName }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{
                            t(
                                'i18n.components.sso.sso_access_card.record_value',
                            )
                        }}
                    </dt>
                    <dd
                        class="font-mono break-all"
                        data-sso-domain-record-value
                    >
                        {{ proof.recordValue }}
                    </dd>
                </dl>
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
