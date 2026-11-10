<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import TenantWelcomePageController from '@/actions/App/Http/Controllers/Tenancy/TenantWelcomePageController';
import DetailItem from '@/components/DetailItem.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import OwnDomainCards from '@/components/tenancy/OwnDomainCards.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { DomainProofRecord } from '@/types/tenancy';

const props = defineProps<{
    address: string;
    team: { name: string; address: string } | null;
    cnameTarget: string;
    domain: string | null;
    domainVerifiedAt: string | null;
    domainProof: DomainProofRecord | null;
    canUpdate: boolean;
}>();

const { t } = useI18n();

const domain = ref<string>(props.domain ?? '');

const { isDirty, promptOpen, confirmLeave, cancelLeave, markSaved } =
    useUnsavedChanges({
        values: () => [domain.value],
        backHref: TenantWelcomePageController.edit.url(),
    });

const form = useForm<{ domain: string }>({ domain: domain.value });

const verification = useForm<Record<string, never>>({});

function save(): void {
    form.transform(() => ({ domain: domain.value })).put(
        TenantWelcomePageController.update.url(),
        { preserveScroll: true, onSuccess: markSaved },
    );
}

function verify(): void {
    verification.post(TenantWelcomePageController.verify.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="t('i18n.pages.organisation.welcome_page.title')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.organisation.welcome_page.title')"
            :description="t('i18n.pages.organisation.welcome_page.description')"
        />

        <div class="flex flex-col gap-6">
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.organisation.welcome_page.address')
                    }}</CardTitle>
                    <CardDescription>{{
                        t(
                            'i18n.pages.organisation.welcome_page.address_description',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <dl class="flex flex-col gap-3">
                        <DetailItem
                            variant="inline"
                            :label="
                                t(
                                    'i18n.pages.organisation.welcome_page.organisation',
                                )
                            "
                        >
                            <span class="break-all" data-welcome-address>{{
                                address
                            }}</span>
                        </DetailItem>
                        <DetailItem
                            v-if="team !== null"
                            variant="inline"
                            :label="
                                t('i18n.pages.organisation.welcome_page.team', {
                                    value1: team.name,
                                })
                            "
                        >
                            <span class="break-all" data-welcome-team-address>{{
                                team.address
                            }}</span>
                        </DetailItem>
                    </dl>
                </CardContent>
            </Card>

            <OwnDomainCards
                v-model="domain"
                :cname-target="cnameTarget"
                :stored-domain="props.domain"
                :domain-verified-at="domainVerifiedAt"
                :domain-proof="domainProof"
                :can-update="canUpdate"
                :domain-error="form.errors.domain"
                :verification-error="verification.errors.domain"
                :is-verifying="verification.processing"
                @verify="verify"
            >
                <FormActions
                    v-if="canUpdate"
                    :dirty="isDirty"
                    :processing="form.processing"
                    type="button"
                    :cancellable="false"
                    @save="save"
                />
            </OwnDomainCards>
        </div>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
