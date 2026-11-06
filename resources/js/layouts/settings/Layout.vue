<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { edit as editNotifications } from '@/actions/App/Http/Controllers/Notifications/NotificationSettingsController';
import Heading from '@/components/Heading.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useI18n } from '@/composables/useI18n';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as absencesIndex } from '@/routes/settings/absences';
import { index as apiTokensIndex } from '@/routes/settings/api-tokens';
import type { NavItem } from '@/types';

const { t } = useI18n();

const sidebarNavItems: NavItem[] = [
    {
        title: t('i18n.layouts.settings.layout.profile'),
        href: editProfile(),
    },
    {
        title: t('i18n.layouts.settings.layout.security'),
        href: editSecurity(),
    },
    {
        title: t('i18n.layouts.settings.layout.appearance'),
        href: editAppearance(),
    },
    {
        title: t('i18n.layouts.settings.layout.notifications'),
        href: editNotifications(),
    },
    {
        title: t('i18n.layouts.settings.layout.absence'),
        href: absencesIndex(),
    },
    {
        title: t('i18n.layouts.settings.layout.api_token'),
        href: apiTokensIndex(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="t('i18n.layouts.settings.layout.settings')"
            :description="
                t(
                    'i18n.layouts.settings.layout.manage_your_profile_and_account_settings',
                )
            "
        />

        <div class="flex flex-col gap-6 lg:flex-row lg:gap-8">
            <aside class="lg:w-56 lg:shrink-0">
                <nav
                    class="flex gap-1 overflow-x-auto lg:sticky lg:top-6 lg:flex-col lg:overflow-visible"
                    :aria-label="t('i18n.layouts.settings.layout.settings')"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'shrink-0 justify-start lg:w-full',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="size-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                    <UiExtensionPoint name="settings.navigation" />
                </nav>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col gap-6">
                <slot />
            </div>
        </div>
    </div>
</template>
