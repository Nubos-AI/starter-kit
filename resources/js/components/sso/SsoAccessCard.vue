<script setup lang="ts">
import FormSwitch from '@/components/FormSwitch.vue';
import InputError from '@/components/InputError.vue';
import SsoDomainProofList from '@/components/sso/SsoDomainProofList.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type { SsoDomainProof } from '@/types/sso';
import type { SelectOption } from '@/types/ui';

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
    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.components.sso.sso_access_card.title')
            }}</CardTitle>
            <CardDescription>{{
                t('i18n.components.sso.sso_access_card.description')
            }}</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-4">
            <div class="grid gap-2 sm:max-w-sm">
                <Label for="allowed_domains">{{
                    t('i18n.components.sso.sso_access_card.allowed_domains')
                }}</Label>
                <Textarea id="allowed_domains" v-model="domains" rows="3" />
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'i18n.components.sso.sso_access_card.allowed_domains_hint',
                        )
                    }}
                </p>
                <InputError :message="errors.allowed_domains" />
                <InputError :message="errors.domain" />
            </div>

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
                    :label="
                        t('i18n.components.sso.sso_access_card.is_enforced')
                    "
                    :description="
                        t('i18n.components.sso.sso_access_card.enforced_hint')
                    "
                    :disabled="!canEnforce"
                    :error="errors.is_enforced"
                />
                <p
                    v-if="!canEnforce && enforceReason !== null"
                    class="text-xs text-muted-foreground"
                    data-sso-enforce-reason
                >
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

            <div class="grid gap-2 sm:max-w-sm">
                <Label for="default_role_id">{{
                    t('i18n.components.sso.sso_access_card.default_role')
                }}</Label>
                <Combobox
                    id="default_role_id"
                    v-model="defaultRoleId"
                    :options="roleOptions"
                    :searchable="roleOptions.length > 8"
                    :disabled="!isJitEnabled"
                    :placeholder="
                        t(
                            'i18n.components.sso.sso_access_card.default_role_placeholder',
                        )
                    "
                />
                <InputError :message="errors.default_role_id" />
            </div>

            <slot />
        </CardContent>
    </Card>
</template>
