<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordConfirmationFields from '@/components/PasswordConfirmationFields.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { update } from '@/routes/password';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.auth.reset_password.reset_password',
        description:
            'i18n.pages.auth.reset_password.please_enter_your_new_password',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head :title="t('i18n.pages.auth.reset_password.reset_password')" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">{{
                    t('i18n.pages.auth.reset_password.email_address')
                }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    readonly
                />
                <InputError :message="errors.email" />
            </div>

            <PasswordConfirmationFields
                :password-label="t('i18n.pages.auth.reset_password.password')"
                :confirmation-label="
                    t('i18n.pages.auth.reset_password.confirm_password')
                "
                :password-rules="passwordRules"
                :password-error="errors.password"
                :confirmation-error="errors.password_confirmation"
                autofocus
            />

            <Button
                type="submit"
                class="mt-4"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                {{ t('i18n.pages.auth.reset_password.reset_password') }}
            </Button>
        </div>
    </Form>
</template>
