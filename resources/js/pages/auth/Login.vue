<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthDivider from '@/components/auth/AuthDivider.vue';
import SsoLoginForm from '@/components/auth/SsoLoginForm.vue';
import FormCheckbox from '@/components/FormCheckbox.vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.auth.login.sign_in_to_your_account',
        description:
            'i18n.pages.auth.login.enter_your_email_address_and_password_to_sign_in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    ssoError?: string | null;
}>();

const remember = ref<boolean>(false);
</script>

<template>
    <Head :title="t('i18n.pages.auth.login.sign_in')" />

    <div v-if="status" class="text-center text-sm font-medium text-success">
        {{ status }}
    </div>

    <PasskeyVerify />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="grid gap-6"
    >
        <div class="grid gap-2">
            <Label for="email">{{
                t('i18n.pages.auth.login.email_address')
            }}</Label>
            <Input
                id="email"
                type="email"
                name="email"
                required
                autofocus
                autocomplete="email"
                :placeholder="t('i18n.pages.auth.login.email_example_com')"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between">
                <Label for="password">{{
                    t('i18n.pages.auth.login.password')
                }}</Label>
                <TextLink
                    v-if="canResetPassword"
                    :href="request()"
                    class="text-sm"
                >
                    {{ t('i18n.pages.auth.login.forgot_password') }}
                </TextLink>
            </div>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="current-password"
                :placeholder="t('i18n.pages.auth.login.password')"
            />
            <InputError :message="errors.password" />
        </div>

        <FormCheckbox
            v-model="remember"
            name="remember"
            :label="t('i18n.pages.auth.login.keep_me_signed_in')"
        />

        <Button
            type="submit"
            class="mt-4"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            {{ t('i18n.pages.auth.login.sign_in') }}
        </Button>

        <div class="text-center text-sm text-muted-foreground">
            {{ t('i18n.pages.auth.login.no_account_yet') }}
            <TextLink :href="register()">{{
                t('i18n.pages.auth.login.register')
            }}</TextLink>
        </div>
    </Form>

    <AuthDivider :label="t('i18n.components.auth.sso_login_form.or')" />

    <SsoLoginForm :error="ssoError ?? null" />
</template>
