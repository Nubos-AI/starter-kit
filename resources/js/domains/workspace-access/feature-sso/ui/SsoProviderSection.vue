<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { useI18n } from '@/shared/composables/useI18n';
import CardSection from '@/shared/ui/CardSection.vue';
import FormField from '@/shared/ui/FormField.vue';
import FormSwitch from '@/shared/ui/FormSwitch.vue';
import { Alert, AlertDescription, AlertTitle } from '@/shared/ui/primitives/alert';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';

defineProps<{
  hasClientSecret: boolean;
  callbackUrl: string;
  errors: Partial<Record<string, string>>;
  isTesting: boolean;
  resetsVerification: boolean;
}>();

const emit = defineEmits<{ test: [] }>();

const isEnabled = defineModel<boolean>('isEnabled', { required: true });
const issuerUrl = defineModel<string>('issuerUrl', { required: true });
const clientId = defineModel<string>('clientId', { required: true });
const clientSecret = defineModel<string>('clientSecret', { required: true });

const { t } = useI18n();
</script>

<template>
  <CardSection
    :title="t('i18n.components.sso.sso_provider_card.title')"
    :description="t('i18n.components.sso.sso_provider_card.description')"
  >
    <FormSwitch
      id="is_enabled"
      v-model="isEnabled"
      :label="t('i18n.components.sso.sso_provider_card.is_enabled')"
      :error="errors.is_enabled"
    />

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField
        for="issuer_url"
        :label="t('i18n.components.sso.sso_provider_card.issuer_url')"
        :error="errors.issuer_url"
        class="sm:col-span-2"
      >
        <Input
          id="issuer_url"
          v-model="issuerUrl"
          type="url"
          :placeholder="t('i18n.components.sso.sso_provider_card.issuer_placeholder')"
        />
      </FormField>
      <FormField
        for="client_id"
        :label="t('i18n.components.sso.sso_provider_card.client_id')"
        :error="errors.client_id"
      >
        <Input id="client_id" v-model="clientId" />
      </FormField>
      <FormField
        for="client_secret"
        :label="t('i18n.components.sso.sso_provider_card.client_secret')"
        :error="errors.client_secret"
      >
        <Input id="client_secret" v-model="clientSecret" type="password" autocomplete="new-password" />
        <p v-if="hasClientSecret" class="text-xs text-muted-foreground" data-sso-secret-hint>
          {{ t('i18n.components.sso.sso_provider_card.secret_set') }}
        </p>
      </FormField>
    </div>

    <Alert v-if="resetsVerification" data-sso-credentials-reset-warning>
      <TriangleAlert />
      <AlertTitle>{{ t('i18n.components.sso.sso_provider_card.credentials_reset_title') }}</AlertTitle>
      <AlertDescription>{{
        t('i18n.components.sso.sso_provider_card.credentials_reset_description')
      }}</AlertDescription>
    </Alert>

    <div class="grid gap-1">
      <span class="text-sm font-medium">{{ t('i18n.components.sso.sso_provider_card.callback_url') }}</span>
      <code class="rounded-md bg-muted px-3 py-2 font-mono text-sm break-all" data-sso-callback-url>{{
        callbackUrl
      }}</code>
      <p class="text-xs text-muted-foreground">
        {{ t('i18n.components.sso.sso_provider_card.callback_hint') }}
      </p>
    </div>

    <div class="grid gap-2">
      <div>
        <Button
          type="button"
          variant="outline"
          :disabled="isTesting || issuerUrl === ''"
          data-sso-test
          @click="emit('test')"
        >
          {{ t('i18n.components.sso.sso_provider_card.test') }}
        </Button>
      </div>
      <p class="text-xs text-muted-foreground" data-sso-test-hint>
        {{ t('i18n.components.sso.sso_provider_card.test_hint') }}
      </p>
    </div>

    <slot />
  </CardSection>
</template>
