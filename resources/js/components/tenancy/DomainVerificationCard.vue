<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import { DOMAIN_VERIFICATION } from '@/lib/statusMaps';
import type { DomainProofRecord } from '@/types/tenancy';

const props = defineProps<{
    storedDomain: string | null;
    domainVerifiedAt: string | null;
    domainProof: DomainProofRecord | null;
    canUpdate: boolean;
    verificationError?: string;
    isVerifying: boolean;
}>();

const emit = defineEmits<{ verify: [] }>();

const { t } = useI18n();

const verificationState = computed<string>(() =>
    props.domainVerifiedAt === null ? 'pending' : 'verified',
);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.components.tenancy.own_domain_cards.verification')
            }}</CardTitle>
            <CardDescription>{{
                t(
                    'i18n.components.tenancy.own_domain_cards.verification_description',
                )
            }}</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-3">
            <p
                v-if="props.storedDomain === null"
                class="text-sm text-muted-foreground"
            >
                {{ t('i18n.components.tenancy.own_domain_cards.no_domain') }}
            </p>
            <div
                v-else
                class="flex flex-wrap items-center gap-3"
                :data-welcome-verification="verificationState"
            >
                <StatusBadge
                    :map="DOMAIN_VERIFICATION"
                    :status="verificationState"
                />
                <span class="text-sm text-muted-foreground">{{
                    props.domainVerifiedAt === null
                        ? t('i18n.components.tenancy.own_domain_cards.pending')
                        : t(
                              'i18n.components.tenancy.own_domain_cards.verified_at',
                              {
                                  value1: formatDateTime(
                                      props.domainVerifiedAt,
                                  ),
                              },
                          )
                }}</span>
            </div>
            <div
                v-if="
                    props.storedDomain !== null &&
                    props.domainVerifiedAt === null &&
                    props.domainProof !== null
                "
                class="grid gap-1"
                data-domain-proof
            >
                <p class="text-xs text-muted-foreground">
                    {{
                        t('i18n.components.tenancy.own_domain_cards.proof_hint')
                    }}
                </p>
                <dl class="grid gap-1 text-xs">
                    <dt class="text-muted-foreground">
                        {{
                            t(
                                'i18n.components.tenancy.own_domain_cards.proof_record_name',
                            )
                        }}
                    </dt>
                    <dd class="font-mono break-all" data-domain-proof-name>
                        {{ props.domainProof.recordName }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{
                            t(
                                'i18n.components.tenancy.own_domain_cards.proof_record_value',
                            )
                        }}
                    </dt>
                    <dd class="font-mono break-all" data-domain-proof-value>
                        {{ props.domainProof.recordValue }}
                    </dd>
                </dl>
            </div>
            <InputError :message="props.verificationError" />
            <div v-if="props.canUpdate && props.storedDomain !== null">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="props.isVerifying"
                    data-welcome-verify
                    @click="emit('verify')"
                >
                    {{ t('i18n.components.tenancy.own_domain_cards.verify') }}
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
