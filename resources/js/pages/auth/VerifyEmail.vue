<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.auth.verify_email.email_verification',
        description:
            'i18n.pages.auth.verify_email.please_verify_your_email_address_using_the_link_we',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('i18n.pages.auth.verify_email.email_verification')" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-success"
    >
        {{
            t(
                'i18n.pages.auth.verify_email.a_new_verification_link_has_been_sent_to_the',
            )
        }}
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            {{ t('i18n.pages.auth.verify_email.resend_verification_email') }}
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            {{ t('i18n.pages.auth.verify_email.sign_out') }}
        </TextLink>
    </Form>
</template>
