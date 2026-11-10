<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { ref } from 'vue';
import SsoLoginController from '@/actions/App/Http/Controllers/Sso/SsoLoginController';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';

const props = defineProps<{
    error: string | null;
}>();

const { t } = useI18n();

const isOpen = ref<boolean>(props.error !== null);
const email = ref<string>('');
</script>

<template>
    <div class="grid gap-4" data-sso-login>
        <AlertError
            v-if="props.error !== null"
            :errors="[props.error]"
            :title="t('i18n.components.auth.sso_login_form.error_title')"
            data-sso-error
        />

        <Button
            v-if="!isOpen"
            type="button"
            variant="outline"
            class="w-full"
            data-sso-start
            @click="isOpen = true"
        >
            <KeyRound class="size-4" />
            {{ t('i18n.components.auth.sso_login_form.sign_in_with_sso') }}
        </Button>

        <Form
            v-else
            v-bind="SsoLoginController.redirect.form()"
            v-slot="{ errors, processing }"
            class="grid gap-4"
        >
            <div class="grid gap-2">
                <Label for="sso-email">{{
                    t('i18n.components.auth.sso_login_form.email')
                }}</Label>
                <Input
                    id="sso-email"
                    v-model="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                />
                <InputError :message="errors.email" />
                <p class="text-xs text-muted-foreground">
                    {{ t('i18n.components.auth.sso_login_form.email_hint') }}
                </p>
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-sso-submit
            >
                <Spinner v-if="processing" />{{
                    t('i18n.components.auth.sso_login_form.continue')
                }}
            </Button>
        </Form>
    </div>
</template>
