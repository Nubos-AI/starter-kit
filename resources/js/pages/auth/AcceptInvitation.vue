<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { accept } from '@/routes/invitations';
import type { SalutationOption } from '@/types/users';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.auth.accept_invitation.complete_account',
        description:
            'i18n.pages.auth.accept_invitation.just_your_name_and_password_then_you_are_ready',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    salutations: SalutationOption[];
    passwordRules: string;
}>();

const salutation = ref<string>('');
const firstName = ref<string>('');
const lastName = ref<string>('');
const inputEmail = ref<string>(props.email);
</script>

<template>
    <Head :title="t('i18n.pages.auth.accept_invitation.complete_account')" />

    <Form
        v-bind="accept.form({ token })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">{{
                    t('i18n.pages.auth.accept_invitation.email')
                }}</Label>
                <Input
                    id="email"
                    v-model="inputEmail"
                    type="email"
                    autocomplete="email"
                    readonly
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="salutation">{{
                    t('i18n.pages.auth.accept_invitation.salutation')
                }}</Label>
                <Select v-model="salutation" name="salutation">
                    <SelectTrigger
                        id="salutation"
                        class="w-full"
                        :aria-invalid="Boolean(errors.salutation)"
                    >
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.pages.auth.accept_invitation.select_salutation',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in salutations"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors.salutation" />
            </div>

            <div class="grid gap-2">
                <Label for="first_name">{{
                    t('i18n.pages.auth.accept_invitation.first_name')
                }}</Label>
                <Input
                    id="first_name"
                    v-model="firstName"
                    name="first_name"
                    autocomplete="given-name"
                    autofocus
                    required
                    :aria-invalid="Boolean(errors.first_name)"
                />
                <InputError :message="errors.first_name" />
            </div>

            <div class="grid gap-2">
                <Label for="last_name">{{
                    t('i18n.pages.auth.accept_invitation.last_name')
                }}</Label>
                <Input
                    id="last_name"
                    v-model="lastName"
                    name="last_name"
                    autocomplete="family-name"
                    required
                    :aria-invalid="Boolean(errors.last_name)"
                />
                <InputError :message="errors.last_name" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{
                    t('i18n.pages.auth.accept_invitation.password')
                }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    :placeholder="
                        t('i18n.pages.auth.accept_invitation.password')
                    "
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    t('i18n.pages.auth.accept_invitation.confirm_password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    :placeholder="
                        t('i18n.pages.auth.accept_invitation.confirm_password')
                    "
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-accept-invitation
            >
                <Spinner v-if="processing" />
                {{ t('i18n.pages.auth.accept_invitation.complete_account') }}
            </Button>
        </div>
    </Form>
</template>
