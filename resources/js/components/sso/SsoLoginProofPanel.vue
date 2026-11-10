<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import { SSO_LOGIN_PROOF } from '@/lib/statusMaps';
import type { SsoLoginProof } from '@/types/sso';

const props = defineProps<{
    proof: SsoLoginProof;
    hasUnsavedChanges: boolean;
    isStarting: boolean;
    error?: string;
}>();

const emit = defineEmits<{ start: [] }>();

const { t } = useI18n();

const blockedReason = computed<string | null>(() => {
    if (!props.proof.canTest) {
        return props.proof.testReason;
    }

    return props.hasUnsavedChanges
        ? t('i18n.components.sso.sso_login_proof_panel.save_first')
        : null;
});
</script>

<template>
    <div class="grid gap-2" data-sso-login-proof>
        <div class="flex items-center gap-3">
            <span class="text-sm font-medium">{{
                t('i18n.components.sso.sso_login_proof_panel.title')
            }}</span>
            <StatusBadge
                :map="SSO_LOGIN_PROOF"
                :status="proof.status"
                :data-sso-login-proof-status="proof.status"
            />
        </div>
        <p
            v-if="proof.verifiedAt !== null"
            class="text-xs text-muted-foreground"
            data-sso-login-proof-verified-at
        >
            {{
                t(
                    proof.status === 'verified'
                        ? 'i18n.components.sso.sso_login_proof_panel.verified_at'
                        : 'i18n.components.sso.sso_login_proof_panel.outdated_since',
                    { value1: formatDateTime(proof.verifiedAt) },
                )
            }}
        </p>
        <p class="text-xs text-muted-foreground">
            {{ t('i18n.components.sso.sso_login_proof_panel.hint') }}
        </p>
        <div>
            <Button
                type="button"
                variant="outline"
                :disabled="isStarting || blockedReason !== null"
                :title="blockedReason ?? undefined"
                data-sso-login-test
                @click="emit('start')"
            >
                {{ t('i18n.components.sso.sso_login_proof_panel.start') }}
            </Button>
        </div>
        <p
            v-if="blockedReason !== null"
            class="text-xs text-muted-foreground"
            data-sso-login-test-reason
        >
            {{ blockedReason }}
        </p>
        <InputError :message="error" />
    </div>
</template>
