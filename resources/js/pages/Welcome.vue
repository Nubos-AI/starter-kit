<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { login, register } from '@/routes';

const { t } = useI18n();

defineOptions({
    layout: {
        title: 'i18n.pages.welcome.welcome_to_nubos',
        description: 'i18n.pages.welcome.sign_in_or_create_a_new_account',
    },
});

defineProps<{
    canRegister: boolean;
    tenant: { name: string } | null;
}>();
</script>

<template>
    <div class="flex flex-col gap-3">
        <Head :title="t('i18n.pages.welcome.welcome')" />

        <p
            v-if="tenant"
            class="text-center text-sm font-medium"
            data-welcome-tenant
        >
            {{ tenant.name }}
        </p>

        <Button as-child class="w-full">
            <Link :href="login()" data-welcome-sign-in>{{
                t('i18n.pages.welcome.sign_in')
            }}</Link>
        </Button>

        <Button v-if="canRegister" as-child variant="outline" class="w-full">
            <Link :href="register()" data-welcome-register>{{
                t('i18n.pages.welcome.register')
            }}</Link>
        </Button>
    </div>
</template>
