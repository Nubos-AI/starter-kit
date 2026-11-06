<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordConfirmationFields from '@/components/PasswordConfirmationFields.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { edit as editSecurity } from '@/routes/security';

const { t } = useI18n();

type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

const currentPassword = ref<string>('');
const password = ref<string>('');
const passwordConfirmation = ref<string>('');

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        currentPassword: currentPassword.value,
        password: password.value,
        passwordConfirmation: passwordConfirmation.value,
    }),
    backHref: editSecurity.url(),
});

function clearPasswords(): void {
    currentPassword.value = '';
    password.value = '';
    passwordConfirmation.value = '';
}

function onSaved(): void {
    clearPasswords();
    markSaved();
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('i18n.pages.settings.security.security_settings')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.settings.security.change_password')"
            :description="
                t(
                    'i18n.pages.settings.security.a_long_random_password_protects_your_account_best',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.security.password')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.security.enter_your_current_password_to_set_a_new_one',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="SecurityController.update.form()"
                    :options="{
                        preserveScroll: true,
                    }"
                    :on-success="onSaved"
                    :on-error="clearPasswords"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="current_password">{{
                            t('i18n.pages.settings.security.current_password')
                        }}</Label>
                        <PasswordInput
                            id="current_password"
                            v-model="currentPassword"
                            name="current_password"
                            autocomplete="current-password"
                            :placeholder="
                                t(
                                    'i18n.pages.settings.security.current_password',
                                )
                            "
                        />
                        <InputError :message="errors.current_password" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <PasswordConfirmationFields
                            v-model:password="password"
                            v-model:confirmation="passwordConfirmation"
                            :password-label="
                                t('i18n.pages.settings.security.new_password')
                            "
                            :confirmation-label="
                                t(
                                    'i18n.pages.settings.security.confirm_password',
                                )
                            "
                            :password-rules="props.passwordRules"
                            :password-error="errors.password"
                            :confirmation-error="errors.password_confirmation"
                        />
                    </div>

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </Form>
            </CardContent>
        </Card>

        <ManageTwoFactor
            :canManageTwoFactor="canManageTwoFactor"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />

        <ManagePasskeys
            :canManagePasskeys="canManagePasskeys"
            :passkeys="passkeys"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
