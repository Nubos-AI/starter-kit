<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { ref } from 'vue';
import SsoLoginController from '@/actions/App/Http/Controllers/Sso/SsoLoginController';
import { useI18n } from '@/shared/composables/useI18n';
import AlertError from '@/shared/ui/AlertError.vue';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import { Spinner } from '@/shared/ui/primitives/spinner';

const props = defineProps<{
  error: string | null;
}>();

const { t } = useI18n();

const isOpen = ref<boolean>(props.error !== null);
const email = ref<string>('');
</script>

<template>
  <div class="grid gap-4" data-sso-login>
    <AlertError
      v-if="props.error !== null"
      :errors="[props.error]"
      :title="t('i18n.components.auth.sso_login_form.error_title')"
      data-sso-error
    />

    <Button v-if="!isOpen" type="button" variant="outline" class="w-full" data-sso-start @click="isOpen = true">
      <KeyRound class="size-4" />
      {{ t('i18n.components.auth.sso_login_form.sign_in_with_sso') }}
    </Button>

    <Form v-else v-bind="SsoLoginController.redirect.form()" v-slot="{ errors, processing }" class="grid gap-4">
      <FormField
        for="sso-email"
        :label="t('i18n.components.auth.sso_login_form.email')"
        :hint="t('i18n.components.auth.sso_login_form.email_hint')"
        :error="errors.email"
      >
        <Input id="sso-email" v-model="email" type="email" name="email" required autocomplete="email" />
      </FormField>

      <Button type="submit" class="w-full" :disabled="processing" data-sso-submit>
        <Spinner v-if="processing" />{{ t('i18n.components.auth.sso_login_form.continue') }}
      </Button>
    </Form>
  </div>
</template>
