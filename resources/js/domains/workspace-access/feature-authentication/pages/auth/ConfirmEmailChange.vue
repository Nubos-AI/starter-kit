<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirm } from '@/routes/email-change';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import { Spinner } from '@/shared/ui/primitives/spinner';

const { t } = useI18n();

defineOptions({
  layout: {
    title: 'i18n.pages.auth.confirm_email_change.confirm_new_email_address',
    description: 'i18n.pages.auth.confirm_email_change.only_confirm_a_change_you_requested',
  },
});

const props = defineProps<{
  token: string;
  email: string;
}>();

const pendingEmail = ref<string>(props.email);
</script>

<template>
  <Head :title="t('i18n.pages.auth.confirm_email_change.confirm_new_email_address')" />

  <Form v-bind="confirm.form({ token })" v-slot="{ errors, processing }">
    <div class="grid gap-6">
      <FormField for="email" :label="t('i18n.pages.auth.confirm_email_change.new_email_address')" :error="errors.email">
        <Input
          id="email"
          v-model="pendingEmail"
          type="email"
          autocomplete="email"
          readonly
          :aria-invalid="Boolean(errors.email)"
        />
      </FormField>

      <Button type="submit" :disabled="processing" data-test="confirm-email-change">
        <Spinner v-if="processing" />
        {{ t('i18n.pages.auth.confirm_email_change.confirm') }}
      </Button>
    </div>
  </Form>
</template>
