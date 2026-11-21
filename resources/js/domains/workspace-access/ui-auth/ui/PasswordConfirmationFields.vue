<script setup lang="ts">
import { computed } from 'vue';
import FormField from '@/shared/ui/FormField.vue';
import PasswordInput from './PasswordInput.vue';

const props = withDefaults(
  defineProps<{
    passwordLabel: string;
    confirmationLabel: string;
    passwordRules: string;
    passwordError?: string;
    confirmationError?: string;
    required?: boolean;
    autofocus?: boolean;
    firstTabindex?: number;
  }>(),
  { required: false, autofocus: false },
);

const password = defineModel<string>('password');
const confirmation = defineModel<string>('confirmation');

const confirmationTabindex = computed<number | undefined>(() =>
  props.firstTabindex === undefined ? undefined : props.firstTabindex + 1,
);
</script>

<template>
  <div class="contents" data-password-confirmation-fields>
    <FormField for="password" :label="props.passwordLabel" :error="props.passwordError">
      <PasswordInput
        id="password"
        v-model="password"
        name="password"
        autocomplete="new-password"
        :required="props.required || undefined"
        :autofocus="props.autofocus || undefined"
        :tabindex="props.firstTabindex"
        :placeholder="props.passwordLabel"
        :passwordrules="props.passwordRules"
      />
    </FormField>

    <FormField for="password_confirmation" :label="props.confirmationLabel" :error="props.confirmationError">
      <PasswordInput
        id="password_confirmation"
        v-model="confirmation"
        name="password_confirmation"
        autocomplete="new-password"
        :required="props.required || undefined"
        :tabindex="confirmationTabindex"
        :placeholder="props.confirmationLabel"
        :passwordrules="props.passwordRules"
      />
    </FormField>
  </div>
</template>
