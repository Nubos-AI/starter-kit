<script setup lang="ts">
import { edit as editNotifications } from '@/actions/App/Http/Controllers/Notifications/NotificationSettingsController';
import Heading from '@/components/Heading.vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import SectionNav from '@/components/SectionNav.vue';
import { useI18n } from '@/composables/useI18n';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as absencesIndex } from '@/routes/settings/absences';
import { index as apiTokensIndex } from '@/routes/settings/api-tokens';
import type { SectionNavEntry } from '@/types/navigation';

const { t } = useI18n();

const sections: SectionNavEntry[] = [
    {
        key: 'profile',
        title: t('i18n.layouts.settings.layout.profile'),
        href: editProfile(),
    },
    {
        key: 'security',
        title: t('i18n.layouts.settings.layout.security'),
        href: editSecurity(),
    },
    {
        key: 'appearance',
        title: t('i18n.layouts.settings.layout.appearance'),
        href: editAppearance(),
    },
    {
        key: 'notifications',
        title: t('i18n.layouts.settings.layout.notifications'),
        href: editNotifications(),
    },
    {
        key: 'absences',
        title: t('i18n.layouts.settings.layout.absence'),
        href: absencesIndex(),
    },
    {
        key: 'api-tokens',
        title: t('i18n.layouts.settings.layout.api_token'),
        href: apiTokensIndex(),
    },
];
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="t('i18n.layouts.settings.layout.settings')"
            :description="
                t(
                    'i18n.layouts.settings.layout.manage_your_profile_and_account_settings',
                )
            "
        />

        <SectionNav
            :entries="sections"
            :label="t('i18n.layouts.settings.layout.settings')"
            data-settings-sections
        >
            <template #append>
                <UiExtensionPoint name="settings.navigation" />
            </template>

            <slot />
        </SectionNav>
    </div>
</template>
