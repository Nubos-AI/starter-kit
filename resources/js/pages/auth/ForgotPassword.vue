<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { login } from '@/routes';
import { email } from '@/routes/password';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.auth.forgot_password.forgot_password',
        description:
            'i18n.pages.auth.forgot_password.enter_your_email_address_to_receive_a_password_reset',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('i18n.pages.auth.forgot_password.forgot_password')" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-success"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">{{
                    t('i18n.pages.auth.forgot_password.email_address')
                }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    autofocus
                    :placeholder="
                        t('i18n.pages.auth.forgot_password.email_example_com')
                    "
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('i18n.pages.auth.forgot_password.send_reset_link') }}
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>{{ t('i18n.pages.auth.forgot_password.or_return_to') }}</span>
            <TextLink :href="login()">{{
                t('i18n.pages.auth.forgot_password.sign_in')
            }}</TextLink>
        </div>
    </div>
</template>
