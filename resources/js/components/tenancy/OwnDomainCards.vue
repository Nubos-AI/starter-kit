<script setup lang="ts">
import DomainVerificationCard from '@/components/tenancy/DomainVerificationCard.vue';
import OwnDomainField from '@/components/tenancy/OwnDomainField.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import type { DomainProofRecord } from '@/types/tenancy';

const props = defineProps<{
    cnameTarget: string;
    storedDomain: string | null;
    domainVerifiedAt: string | null;
    domainProof: DomainProofRecord | null;
    canUpdate: boolean;
    domainError?: string;
    verificationError?: string;
    isVerifying: boolean;
}>();

const emit = defineEmits<{ verify: [] }>();

const domain = defineModel<string>({ required: true });

const { t } = useI18n();
</script>

<template>
    <div class="contents">
        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.components.tenancy.own_domain_cards.domain')
                }}</CardTitle>
                <CardDescription>{{
                    t(
                        'i18n.components.tenancy.own_domain_cards.domain_description',
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <OwnDomainField
                    v-model="domain"
                    :cname-target="props.cnameTarget"
                    :can-update="props.canUpdate"
                    :domain-error="props.domainError"
                />
                <slot />
            </CardContent>
        </Card>

        <DomainVerificationCard
            :stored-domain="props.storedDomain"
            :domain-verified-at="props.domainVerifiedAt"
            :domain-proof="props.domainProof"
            :can-update="props.canUpdate"
            :verification-error="props.verificationError"
            :is-verifying="props.isVerifying"
            @verify="emit('verify')"
        />
    </div>
</template>
