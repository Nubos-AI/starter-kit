<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { useI18n } from '@/composables/useI18n';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

const { t } = useI18n();

const showRecoveryInput = ref<boolean>(false);
const code = ref<string>('');

const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: t('i18n.pages.auth.two_factor_challenge.recovery_code'),
            description: t(
                'i18n.pages.auth.two_factor_challenge.please_confirm_access_to_your_account_using_one_of',
            ),
            buttonText: t(
                'i18n.pages.auth.two_factor_challenge.sign_in_with_an_authentication_code',
            ),
        };
    }

    return {
        title: t('i18n.pages.auth.two_factor_challenge.authentication_code'),
        description: t(
            'i18n.pages.auth.two_factor_challenge.enter_the_authentication_code_from_your_authenticator_app',
        ),
        buttonText: t(
            'i18n.pages.auth.two_factor_challenge.sign_in_with_a_recovery_code',
        ),
    };
});

watchEffect(() => {
    setLayoutProps({
        title: authConfigContent.value.title,
        description: authConfigContent.value.description,
    });
});

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};
</script>

<template>
    <Head
        :title="
            t('i18n.pages.auth.two_factor_challenge.two_factor_authentication')
        "
    />

    <Form
        v-bind="store.form()"
        class="grid gap-4"
        reset-on-error
        @error="code = ''"
        #default="{ errors, processing, clearErrors }"
    >
        <div
            v-if="!showRecoveryInput"
            class="flex flex-col items-center gap-3 text-center"
        >
            <input type="hidden" name="code" :value="code" />
            <InputOTP
                id="otp"
                v-model="code"
                :maxlength="6"
                :disabled="processing"
                autofocus
            >
                <InputOTPGroup>
                    <InputOTPSlot
                        v-for="index in 6"
                        :key="index"
                        :index="index - 1"
                    />
                </InputOTPGroup>
            </InputOTP>
            <InputError :message="errors.code" />
        </div>

        <div v-else class="grid gap-2">
            <Input
                name="recovery_code"
                type="text"
                :placeholder="
                    t(
                        'i18n.pages.auth.two_factor_challenge.enter_recovery_code',
                    )
                "
                autofocus
                required
            />
            <InputError :message="errors.recovery_code" />
        </div>

        <Button type="submit" :disabled="processing">{{
            t('i18n.pages.auth.two_factor_challenge.continue')
        }}</Button>

        <div class="text-center text-sm text-muted-foreground">
            <span
                >{{ t('i18n.pages.auth.two_factor_challenge.or_you_can') }}
            </span>
            <Button
                type="button"
                variant="link"
                size="sm"
                class="h-auto p-0 text-foreground underline decoration-subtlest underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                @click="() => toggleRecoveryMode(clearErrors)"
            >
                {{ authConfigContent.buttonText }}
            </Button>
        </div>
    </Form>
</template>
