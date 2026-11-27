<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { SalutationOption } from '@/domains/workspace-access/data';
import { PasswordConfirmationFields } from '@/domains/workspace-access/ui-auth';
import { SalutationSelect } from '@/domains/workspace-access/ui-users';
import { accept } from '@/routes/invitations';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import { Spinner } from '@/shared/ui/primitives/spinner';

const { t } = useI18n();

defineOptions({
  layout: {
    title: 'i18n.pages.auth.accept_invitation.complete_account',
    description: 'i18n.pages.auth.accept_invitation.just_your_name_and_password_then_you_are_ready',
  },
});

const props = defineProps<{
  token: string;
  email: string;
  salutations: SalutationOption[];
  passwordRules: string;
}>();

const salutation = ref<string>('');
const firstName = ref<string>('');
const lastName = ref<string>('');
const inputEmail = ref<string>(props.email);
</script>

<template>
  <Head :title="t('i18n.pages.auth.accept_invitation.complete_account')" />

  <Form
    v-bind="accept.form({ token })"
    :reset-on-success="['password', 'password_confirmation']"
    v-slot="{ errors, processing }"
  >
    <div class="grid gap-6">
      <FormField for="email" :label="t('i18n.pages.auth.accept_invitation.email')" :error="errors.email">
        <Input id="email" v-model="inputEmail" type="email" autocomplete="email" readonly />
      </FormField>

      <FormField for="salutation" :label="t('i18n.pages.auth.accept_invitation.salutation')" :error="errors.salutation">
        <SalutationSelect
          id="salutation"
          v-model="salutation"
          :options="salutations"
          :invalid="Boolean(errors.salutation)"
        />
      </FormField>

      <FormField for="first_name" :label="t('i18n.pages.auth.accept_invitation.first_name')" :error="errors.first_name">
        <Input
          id="first_name"
          v-model="firstName"
          name="first_name"
          autocomplete="given-name"
          autofocus
          required
          :aria-invalid="Boolean(errors.first_name)"
        />
      </FormField>

      <FormField for="last_name" :label="t('i18n.pages.auth.accept_invitation.last_name')" :error="errors.last_name">
        <Input
          id="last_name"
          v-model="lastName"
          name="last_name"
          autocomplete="family-name"
          required
          :aria-invalid="Boolean(errors.last_name)"
        />
      </FormField>

      <PasswordConfirmationFields
        :password-label="t('i18n.pages.auth.accept_invitation.password')"
        :confirmation-label="t('i18n.pages.auth.accept_invitation.confirm_password')"
        :password-rules="passwordRules"
        :password-error="errors.password"
        :confirmation-error="errors.password_confirmation"
      />

      <Button type="submit" class="mt-4" :disabled="processing" data-accept-invitation>
        <Spinner v-if="processing" />
        {{ t('i18n.pages.auth.accept_invitation.complete_account') }}
      </Button>
    </div>
  </Form>
</template>
