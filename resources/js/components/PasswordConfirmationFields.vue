<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Label } from '@/components/ui/label';

const props = withDefaults(
    defineProps<{
        passwordLabel: string;
        confirmationLabel: string;
        passwordRules: string;
        passwordError?: string;
        confirmationError?: string;
        required?: boolean;
        autofocus?: boolean;
        firstTabindex?: number;
    }>(),
    { required: false, autofocus: false },
);

const password = defineModel<string>('password');
const confirmation = defineModel<string>('confirmation');

const confirmationTabindex = computed<number | undefined>(() =>
    props.firstTabindex === undefined ? undefined : props.firstTabindex + 1,
);
</script>

<template>
    <div class="contents" data-password-confirmation-fields>
        <div class="grid gap-2">
            <Label for="password">{{ props.passwordLabel }}</Label>
            <PasswordInput
                id="password"
                v-model="password"
                name="password"
                autocomplete="new-password"
                :required="props.required || undefined"
                :autofocus="props.autofocus || undefined"
                :tabindex="props.firstTabindex"
                :placeholder="props.passwordLabel"
                :passwordrules="props.passwordRules"
            />
            <InputError :message="props.passwordError" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">{{
                props.confirmationLabel
            }}</Label>
            <PasswordInput
                id="password_confirmation"
                v-model="confirmation"
                name="password_confirmation"
                autocomplete="new-password"
                :required="props.required || undefined"
                :tabindex="confirmationTabindex"
                :placeholder="props.confirmationLabel"
                :passwordrules="props.passwordRules"
            />
            <InputError :message="props.confirmationError" />
        </div>
    </div>
</template>
