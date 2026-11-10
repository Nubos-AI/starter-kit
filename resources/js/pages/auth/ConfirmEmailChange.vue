<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { confirm } from '@/routes/email-change';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.auth.confirm_email_change.confirm_new_email_address',
        description:
            'i18n.pages.auth.confirm_email_change.only_confirm_a_change_you_requested',
    },
});

const props = defineProps<{
    token: string;
    email: string;
}>();

const pendingEmail = ref<string>(props.email);
</script>

<template>
    <Head
        :title="
            t('i18n.pages.auth.confirm_email_change.confirm_new_email_address')
        "
    />

    <Form v-bind="confirm.form({ token })" v-slot="{ errors, processing }">
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">{{
                    t('i18n.pages.auth.confirm_email_change.new_email_address')
                }}</Label>
                <Input
                    id="email"
                    v-model="pendingEmail"
                    type="email"
                    autocomplete="email"
                    readonly
                    :aria-invalid="Boolean(errors.email)"
                />
                <InputError :message="errors.email" data-test="email-error" />
            </div>

            <Button
                type="submit"
                :disabled="processing"
                data-test="confirm-email-change"
            >
                <Spinner v-if="processing" />
                {{ t('i18n.pages.auth.confirm_email_change.confirm') }}
            </Button>
        </div>
    </Form>
</template>
