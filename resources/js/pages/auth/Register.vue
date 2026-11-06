<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordConfirmationFields from '@/components/PasswordConfirmationFields.vue';
import SalutationSelect from '@/components/SalutationSelect.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { SalutationOption } from '@/types/users';

const { t } = useI18n();

const page = usePage();

const requiresCompanyName = computed(
    () => page.props.registration?.requiresCompanyName !== false,
);

defineProps<{
    passwordRules: string;
    salutations: SalutationOption[];
}>();

const salutation = ref<string>('unknown');

defineOptions({
    layout: {
        title: 'i18n.pages.auth.register.create_account',
        description:
            'i18n.pages.auth.register.enter_your_details_to_create_an_account',
    },
});
</script>

<template>
    <Head :title="t('i18n.pages.auth.register.register')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div v-if="requiresCompanyName" class="grid gap-2">
                <Label for="company_name">{{
                    t('i18n.pages.auth.register.company_name')
                }}</Label>
                <Input
                    id="company_name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="organization"
                    name="company_name"
                    :placeholder="t('i18n.pages.auth.register.company_name')"
                />
                <InputError :message="errors.company_name" />
            </div>

            <div class="grid gap-2">
                <Label for="salutation">{{
                    t('i18n.pages.auth.register.salutation')
                }}</Label>
                <SalutationSelect
                    id="salutation"
                    v-model="salutation"
                    :options="salutations"
                    :tabindex="2"
                />
                <InputError :message="errors.salutation" />
            </div>

            <div class="grid gap-2">
                <Label for="first_name">{{
                    t('i18n.pages.auth.register.first_name')
                }}</Label>
                <Input
                    id="first_name"
                    type="text"
                    required
                    :autofocus="!requiresCompanyName"
                    :tabindex="3"
                    autocomplete="given-name"
                    name="first_name"
                    :placeholder="t('i18n.pages.auth.register.first_name')"
                />
                <InputError :message="errors.first_name" />
            </div>

            <div class="grid gap-2">
                <Label for="last_name">{{
                    t('i18n.pages.auth.register.last_name')
                }}</Label>
                <Input
                    id="last_name"
                    type="text"
                    required
                    :tabindex="4"
                    autocomplete="family-name"
                    name="last_name"
                    :placeholder="t('i18n.pages.auth.register.last_name')"
                />
                <InputError :message="errors.last_name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{
                    t('i18n.pages.auth.register.email_address')
                }}</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="5"
                    autocomplete="email"
                    name="email"
                    :placeholder="
                        t('i18n.pages.auth.register.email_example_com')
                    "
                />
                <InputError :message="errors.email" />
            </div>

            <PasswordConfirmationFields
                :password-label="t('i18n.pages.auth.register.password')"
                :confirmation-label="
                    t('i18n.pages.auth.register.confirm_password')
                "
                :password-rules="passwordRules"
                :password-error="errors.password"
                :confirmation-error="errors.password_confirmation"
                required
                :first-tabindex="6"
            />

            <Button
                type="submit"
                class="mt-2"
                tabindex="8"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                {{ t('i18n.pages.auth.register.create_account') }}
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            {{ t('i18n.pages.auth.register.already_have_an_account') }}
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="9"
                >{{ t('i18n.pages.auth.register.sign_in') }}</TextLink
            >
        </div>
    </Form>
</template>
