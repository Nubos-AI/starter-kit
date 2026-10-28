<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import MaintenanceLockBanner from '@/components/maintenance/MaintenanceLockBanner.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { home } from '@/routes';

const { t } = useI18n();

interface Props {
    status: number;
}

interface ErrorCopy {
    title: string;
    description: string;
}

const props = defineProps<Props>();

const copy: Record<number, ErrorCopy> = {
    403: {
        title: t('i18n.pages.errors.error.access_denied'),
        description: t(
            'i18n.pages.errors.error.you_do_not_have_permission_to_access_this_area',
        ),
    },
    404: {
        title: t('i18n.pages.errors.error.page_not_found'),
        description: t(
            'i18n.pages.errors.error.this_address_does_not_exist_the_entry_may_have',
        ),
    },
    419: {
        title: t('i18n.pages.errors.error.session_expired'),
        description: t(
            'i18n.pages.errors.error.your_session_has_expired_please_sign_in_again_and',
        ),
    },
    429: {
        title: t('i18n.pages.errors.error.too_many_requests'),
        description: t(
            'i18n.pages.errors.error.too_many_requests_were_received_in_a_short_time',
        ),
    },
    500: {
        title: t('i18n.pages.errors.error.an_error_occurred'),
        description: t(
            'i18n.pages.errors.error.the_request_could_not_be_processed_the_incident_has',
        ),
    },
    503: {
        title: t('i18n.pages.errors.error.temporarily_unavailable'),
        description: t(
            'i18n.pages.errors.error.this_area_is_currently_unavailable_for_example_due_to',
        ),
    },
};

const fallback: ErrorCopy = {
    title: t('i18n.pages.errors.error.an_error_occurred'),
    description: t(
        'i18n.pages.errors.error.the_request_could_not_be_processed_please_try_again',
    ),
};

const current = computed<ErrorCopy>(() => copy[props.status] ?? fallback);
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <Head :title="current.title" />

        <div
            class="flex w-full max-w-md flex-col items-center gap-6 text-center"
        >
            <AppLogoIcon class="size-9" />

            <div class="space-y-2">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ t('i18n.pages.errors.error.errors') }} {{ status }}
                </p>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ current.title }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ current.description }}
                </p>
            </div>

            <MaintenanceLockBanner
                v-if="status === 503"
                class="rounded-md border text-left"
            />

            <Button as-child>
                <Link :href="home()">{{
                    t('i18n.pages.errors.error.go_to_home_page')
                }}</Link>
            </Button>
        </div>
    </div>
</template>
