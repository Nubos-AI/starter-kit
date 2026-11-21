<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import TenantWelcomePageController from '@/actions/App/Http/Controllers/Tenancy/TenantWelcomePageController';
import type { DomainProofRecord } from '@/domains/workspace-access/data';
import { DomainVerificationCard } from '@/domains/workspace-access/ui-organisation';
import { OwnDomainField } from '@/domains/workspace-access/ui-organisation';
import { useI18n } from '@/shared/composables/useI18n';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import CardSection from '@/shared/ui/CardSection.vue';
import DetailItem from '@/shared/ui/DetailItem.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

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

const { isDirty, promptOpen, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => [domain.value],
  backHref: TenantWelcomePageController.edit.url(),
});

const form = useForm<{ domain: string }>({ domain: domain.value });

const verification = useForm<Record<string, never>>({});

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    {
      key: 'address',
      title: t('i18n.pages.organisation.welcome_page.address'),
    },
    {
      key: 'domain',
      title: t('i18n.components.tenancy.own_domain_cards.domain'),
      fields: ['domain'],
    },
    {
      key: 'verification',
      title: t('i18n.components.tenancy.own_domain_cards.verification'),
    },
  ],
  () => form.errors,
);

function save(): void {
  form
    .transform(() => ({ domain: domain.value }))
    .put(TenantWelcomePageController.update.url(), {
      preserveScroll: true,
      onSuccess: markSaved,
      onError: revealErrors,
    });
}

function verify(): void {
  verification.post(TenantWelcomePageController.verify.url(), {
    preserveScroll: true,
  });
}
</script>

<template>
  <PageShell
    :title="t('i18n.pages.organisation.welcome_page.title')"
    :description="t('i18n.pages.organisation.welcome_page.description')"
  >
    <SectionNav
      v-model="activeSection"
      :entries="sections"
      :label="t('i18n.pages.organisation.welcome_page.sections.label')"
      data-welcome-sections
    >
      <SectionPanel section="address" :actions="false">
        <CardSection
          :title="t('i18n.pages.organisation.welcome_page.address')"
          :description="t('i18n.pages.organisation.welcome_page.address_description')"
        >
          <dl class="flex flex-col gap-3">
            <DetailItem variant="inline" :label="t('i18n.pages.organisation.welcome_page.organisation')">
              <span class="break-all" data-welcome-address>{{ address }}</span>
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
              <span class="break-all" data-welcome-team-address>{{ team.address }}</span>
            </DetailItem>
          </dl>
        </CardSection>
      </SectionPanel>

      <SectionPanel section="domain">
        <CardSection
          :title="t('i18n.components.tenancy.own_domain_cards.domain')"
          :description="t('i18n.components.tenancy.own_domain_cards.domain_description')"
        >
          <OwnDomainField
            v-model="domain"
            :cname-target="cnameTarget"
            :can-update="canUpdate"
            :domain-error="form.errors.domain"
          />
        </CardSection>
      </SectionPanel>

      <SectionPanel section="verification" bare>
        <DomainVerificationCard
          :stored-domain="props.domain"
          :domain-verified-at="domainVerifiedAt"
          :domain-proof="domainProof"
          :can-update="canUpdate"
          :verification-error="verification.errors.domain"
          :is-verifying="verification.processing"
          @verify="verify"
        />
      </SectionPanel>

      <template v-if="canUpdate" #actions>
        <FormActions :dirty="isDirty" :processing="form.processing" type="button" :cancellable="false" @save="save" />
      </template>
    </SectionNav>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
