<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { KeyRound } from '@lucide/vue';
import AuthDivider from '@/components/auth/AuthDivider.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
};

const props = defineProps<Props>();

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    ...(props.routes
        ? {
              routes: {
                  options: props.routes.options.url,
                  submit: props.routes.submit.url,
              },
          }
        : {}),
    onSuccess: (response) => {
        router.visit(response.redirect ?? '/dashboard');
    },
});
</script>

<template>
    <div v-if="isSupported" class="grid gap-6">
        <div class="grid gap-2">
            <Button
                type="button"
                variant="outline"
                :disabled="isLoading"
                @click="verify"
            >
                <Spinner v-if="isLoading" />
                <KeyRound v-else class="size-4" />
                {{
                    isLoading
                        ? (props.loadingLabel ??
                          t('i18n.components.passkey_verify.signing_in'))
                        : (props.label ??
                          t(
                              'i18n.components.passkey_verify.sign_in_with_a_passkey',
                          ))
                }}
            </Button>

            <InputError class="text-center" :message="error ?? undefined" />
        </div>

        <AuthDivider
            :label="
                props.separator ??
                t(
                    'i18n.components.passkey_verify.or_continue_with_your_email_address',
                )
            "
        />
    </div>
</template>
