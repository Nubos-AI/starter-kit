<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { store } from '@/routes/password/confirm';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.auth.confirm_password.confirm_password',
        description:
            'i18n.pages.auth.confirm_password.this_is_a_protected_area_of_the_application_please',
    },
});
</script>

<template>
    <Head :title="t('i18n.pages.auth.confirm_password.confirm_password')" />

    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        :label="t('i18n.pages.auth.confirm_password.confirm_with_a_passkey')"
        :loading-label="t('i18n.pages.auth.confirm_password.confirming')"
        :separator="
            t('i18n.pages.auth.confirm_password.or_confirm_with_your_password')
        "
    />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
        class="grid gap-6"
    >
        <div class="grid gap-2">
            <Label for="password">{{
                t('i18n.pages.auth.confirm_password.password')
            }}</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="current-password"
                autofocus
            />
            <InputError :message="errors.password" />
        </div>

        <Button :disabled="processing" data-test="confirm-password-button">
            <Spinner v-if="processing" />
            {{ t('i18n.pages.auth.confirm_password.confirm_password') }}
        </Button>
    </Form>
</template>
