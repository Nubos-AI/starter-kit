<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserPasswordsController from '@/actions/App/Http/Controllers/Users/UserPasswordsController';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';
import ReasonedButton from '@/shared/ui/ReasonedButton.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

const { t } = useI18n();

const props = defineProps<{
  userId: string;
  canReceiveResetLink: boolean;
  backHref: string;
}>();

const password = ref<string>('');
const passwordConfirmation = ref<string>('');
const resetLinkPending = ref<boolean>(false);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    password: password.value,
    passwordConfirmation: passwordConfirmation.value,
  }),
  backHref: () => props.backHref,
});

const resetLinkReason = computed<string | null>(() =>
  props.canReceiveResetLink
    ? null
    : t('i18n.components.users.user_password_card.a_reset_link_can_only_be_sent_to_users'),
);

function onSaved(): void {
  password.value = '';
  passwordConfirmation.value = '';
  markSaved();
}

function sendResetLink(): void {
  resetLinkPending.value = true;

  router.post(
    UserPasswordsController.sendResetLink.url({ user: props.userId }),
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        resetLinkPending.value = false;
      },
    },
  );
}
</script>

<template>
  <div class="contents">
    <Card data-user-password-card>
      <CardHeader>
        <CardTitle>{{ t('i18n.components.users.user_password_card.password') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.components.users.user_password_card.a_new_password_signs_this_user_out_everywhere_they') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form
          v-bind="UserPasswordsController.update.form({ user: userId })"
          :on-success="onSaved"
          class="flex flex-col gap-4"
          v-slot="{ errors, processing }"
        >
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField
              for="user-password"
              :label="t('i18n.components.users.user_password_card.new_password')"
              :error="errors.password"
            >
              <Input
                id="user-password"
                v-model="password"
                name="password"
                type="password"
                autocomplete="new-password"
                :aria-invalid="Boolean(errors.password)"
              />
            </FormField>

            <FormField
              for="user-password-confirmation"
              :label="t('i18n.components.users.user_password_card.confirm_password')"
              :error="errors.password_confirmation"
            >
              <Input
                id="user-password-confirmation"
                v-model="passwordConfirmation"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                :aria-invalid="Boolean(errors.password_confirmation)"
              />
            </FormField>
          </div>

          <div class="flex items-center justify-between gap-2">
            <ReasonedButton
              variant="outline"
              :reason="resetLinkReason"
              :disabled="resetLinkPending"
              data-send-reset-link
              @click="sendResetLink"
            >
              {{ t('i18n.components.users.user_password_card.send_reset_link') }}
            </ReasonedButton>

            <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
          </div>
        </Form>
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </div>
</template>
