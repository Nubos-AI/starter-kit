<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SsoSettingsController from '@/actions/App/Http/Controllers/Sso/SsoSettingsController';
import { useI18n } from '@/shared/composables/useI18n';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { SelectOption } from '@/shared/types/ui';
import FormActions from '@/shared/ui/FormActions.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import type { ScimSettings, SsoConnectionSettings, SsoLoginProof } from '../../types/sso';
import ScimProvisioningSection from '../../ui/ScimProvisioningSection.vue';
import SsoAccessSection from '../../ui/SsoAccessSection.vue';
import SsoLoginProofPanel from '../../ui/SsoLoginProofPanel.vue';
import SsoProviderSection from '../../ui/SsoProviderSection.vue';

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

const resetsVerification = computed<boolean>(() => {
  const hasProof = props.connection.verifiedDomains.length > 0 || props.loginProof.status !== 'missing';
  const hasNewCredentials =
    issuerUrl.value !== (props.connection.issuerUrl ?? '') ||
    clientId.value !== (props.connection.clientId ?? '') ||
    clientSecret.value !== '';

  return hasProof && hasNewCredentials;
});

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

const { isDirty, promptOpen, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
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

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    {
      key: 'provider',
      title: t('i18n.components.sso.sso_provider_card.title'),
      fields: ['is_enabled', 'issuer_url', 'client_id', 'client_secret', 'login_test'],
    },
    {
      key: 'access',
      title: t('i18n.components.sso.sso_access_card.title'),
      fields: ['allowed_domains', 'domain', 'is_enforced', 'is_jit_enabled', 'default_role_id'],
    },
    {
      key: 'scim',
      title: t('i18n.components.sso.scim_provisioning_card.title'),
    },
  ],
  () => errors.value,
);

function save(): void {
  form
    .transform(() => payload.value)
    .put(SsoSettingsController.update.url(), {
      preserveScroll: true,
      onSuccess: () => {
        clientSecret.value = '';
        markSaved();
      },
      onError: revealErrors,
    });
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
  <PageShell :title="t('i18n.pages.sso.settings.title')" :description="t('i18n.pages.sso.settings.description')">
    <SectionNav
      v-model="activeSection"
      :entries="sections"
      :label="t('i18n.pages.sso.settings.sections.label')"
      data-sso-sections
    >
      <SectionPanel section="provider">
        <SsoProviderSection
          v-model:is-enabled="isEnabled"
          v-model:issuer-url="issuerUrl"
          v-model:client-id="clientId"
          v-model:client-secret="clientSecret"
          :has-client-secret="connection.hasClientSecret"
          :callback-url="callbackUrl"
          :errors="errors"
          :is-testing="testing.processing"
          :resets-verification="resetsVerification"
          @test="test"
        >
          <SsoLoginProofPanel
            :proof="loginProof"
            :has-unsaved-changes="isDirty"
            :is-starting="loginTest.processing"
            :error="errors.login_test"
            @start="startLoginTest"
          />
        </SsoProviderSection>
      </SectionPanel>

      <SectionPanel section="access">
        <SsoAccessSection
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
        />
      </SectionPanel>

      <SectionPanel section="scim" :actions="false">
        <ScimProvisioningSection :scim="scim" :secret="scimToken" :is-rotating="rotation.processing" @rotate="rotate" />
      </SectionPanel>

      <template #actions>
        <FormActions :dirty="isDirty" :processing="form.processing" type="button" :cancellable="false" @save="save" />
      </template>
    </SectionNav>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
