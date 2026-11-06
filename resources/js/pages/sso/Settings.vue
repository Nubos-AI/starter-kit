<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SsoSettingsController from '@/actions/App/Http/Controllers/Sso/SsoSettingsController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import ScimProvisioningCard from '@/components/sso/ScimProvisioningCard.vue';
import SsoAccessCard from '@/components/sso/SsoAccessCard.vue';
import SsoLoginProofPanel from '@/components/sso/SsoLoginProofPanel.vue';
import SsoProviderCard from '@/components/sso/SsoProviderCard.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type {
    ScimSettings,
    SsoConnectionSettings,
    SsoLoginProof,
} from '@/types/sso';
import type { SelectOption } from '@/types/ui';

const props = defineProps<{
    connection: SsoConnectionSettings;
    loginProof: SsoLoginProof;
    roleOptions: SelectOption[];
    callbackUrl: string;
    scim: ScimSettings;
    scimToken: string | null;
}>();

const { t } = useI18n();

const isEnabled = ref<boolean>(props.connection.isEnabled);
const isEnforced = ref<boolean>(props.connection.isEnforced);
const isJitEnabled = ref<boolean>(props.connection.isJitEnabled);
const issuerUrl = ref<string>(props.connection.issuerUrl ?? '');
const clientId = ref<string>(props.connection.clientId ?? '');
const clientSecret = ref<string>('');
const domains = ref<string>(props.connection.allowedDomains.join('\n'));
const defaultRoleId = ref<string | null>(props.connection.defaultRoleId);

const allowedDomains = computed<string[]>(() =>
    domains.value
        .split('\n')
        .map((domain) => domain.trim())
        .filter((domain) => domain !== ''),
);

const payload = computed(() => ({
    is_enabled: isEnabled.value,
    is_enforced: isEnforced.value,
    is_jit_enabled: isJitEnabled.value,
    issuer_url: issuerUrl.value,
    client_id: clientId.value,
    client_secret: clientSecret.value,
    allowed_domains: allowedDomains.value,
    default_role_id: defaultRoleId.value,
}));

const { isDirty, promptOpen, confirmLeave, cancelLeave, markSaved } =
    useUnsavedChanges({
        values: () => payload.value,
        backHref: SsoSettingsController.edit.url(),
    });

const form = useForm<Record<string, never>>({});
const testing = useForm<Record<string, never>>({});
const loginTest = useForm<Record<string, never>>({});
const rotation = useForm<Record<string, never>>({});
const domainCheck = useForm<Record<string, never>>({});

const errors = computed<Partial<Record<string, string>>>(() => ({
    ...testing.errors,
    ...loginTest.errors,
    ...domainCheck.errors,
    ...form.errors,
}));

function save(): void {
    form.transform(() => payload.value).put(
        SsoSettingsController.update.url(),
        {
            preserveScroll: true,
            onSuccess: () => {
                clientSecret.value = '';
                markSaved();
            },
        },
    );
}

function test(): void {
    testing
        .transform(() => ({ issuer_url: issuerUrl.value }))
        .post(SsoSettingsController.test.url(), { preserveScroll: true });
}

function startLoginTest(): void {
    loginTest.post(SsoSettingsController.startLoginTest.url(), {
        preserveScroll: true,
    });
}

function verifyDomain(domain: string): void {
    domainCheck
        .transform(() => ({ domain }))
        .post(SsoSettingsController.verifyDomain.url(), {
            preserveScroll: true,
        });
}

function rotate(): void {
    rotation.post(SsoSettingsController.rotateScimToken.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head :title="t('i18n.pages.sso.settings.title')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.sso.settings.title')"
            :description="t('i18n.pages.sso.settings.description')"
        />

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <SsoProviderCard
                v-model:is-enabled="isEnabled"
                v-model:issuer-url="issuerUrl"
                v-model:client-id="clientId"
                v-model:client-secret="clientSecret"
                :has-client-secret="connection.hasClientSecret"
                :callback-url="callbackUrl"
                :errors="errors"
                :is-testing="testing.processing"
                @test="test"
            >
                <SsoLoginProofPanel
                    :proof="loginProof"
                    :has-unsaved-changes="isDirty"
                    :is-starting="loginTest.processing"
                    :error="errors.login_test"
                    @start="startLoginTest"
                />
            </SsoProviderCard>

            <SsoAccessCard
                v-model:domains="domains"
                v-model:is-enforced="isEnforced"
                v-model:is-jit-enabled="isJitEnabled"
                v-model:default-role-id="defaultRoleId"
                :role-options="roleOptions"
                :domain-proofs="connection.domainProofs"
                :verified-domains="connection.verifiedDomains"
                :is-verifying-domain="domainCheck.processing"
                :errors="errors"
                :can-enforce="loginProof.canEnforce"
                :enforce-reason="loginProof.enforceReason"
                @verify-domain="verifyDomain"
            >
                <FormActions
                    :dirty="isDirty"
                    :processing="form.processing"
                    type="button"
                    :cancellable="false"
                    @save="save"
                />
            </SsoAccessCard>
        </div>

        <ScimProvisioningCard
            :scim="scim"
            :secret="scimToken"
            :is-rotating="rotation.processing"
            @rotate="rotate"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
