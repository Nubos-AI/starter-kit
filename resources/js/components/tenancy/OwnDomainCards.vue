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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import { DOMAIN_VERIFICATION } from '@/lib/statusMaps';

const props = defineProps<{
    cnameTarget: string;
    storedDomain: string | null;
    domainVerifiedAt: string | null;
    canUpdate: boolean;
    domainError?: string;
    verificationError?: string;
    isVerifying: boolean;
}>();

const emit = defineEmits<{ verify: [] }>();

const domain = defineModel<string>({ required: true });

const { t } = useI18n();

const verificationState = computed<string>(() =>
    props.domainVerifiedAt === null ? 'pending' : 'verified',
);
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
            <CardContent class="grid gap-2">
                <Label for="domain">{{
                    t('i18n.components.tenancy.own_domain_cards.domain')
                }}</Label>
                <Input
                    id="domain"
                    v-model="domain"
                    :disabled="!canUpdate"
                    class="sm:max-w-sm"
                />
                <InputError :message="domainError" />
                <p class="text-xs text-muted-foreground" data-welcome-cname>
                    {{
                        t(
                            'i18n.components.tenancy.own_domain_cards.cname_hint',
                            { value1: cnameTarget },
                        )
                    }}
                </p>
            </CardContent>
        </Card>

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
                    v-if="storedDomain === null"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t('i18n.components.tenancy.own_domain_cards.no_domain')
                    }}
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
                        domainVerifiedAt === null
                            ? t(
                                  'i18n.components.tenancy.own_domain_cards.pending',
                              )
                            : t(
                                  'i18n.components.tenancy.own_domain_cards.verified_at',
                                  { value1: formatDateTime(domainVerifiedAt) },
                              )
                    }}</span>
                </div>
                <InputError :message="verificationError" />
                <div v-if="canUpdate && storedDomain !== null">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="isVerifying"
                        data-welcome-verify
                        @click="emit('verify')"
                    >
                        {{
                            t('i18n.components.tenancy.own_domain_cards.verify')
                        }}
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
