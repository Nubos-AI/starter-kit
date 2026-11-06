<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

const { t } = useI18n();

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <div v-if="canManageTwoFactor" class="flex flex-col gap-6">
        <Card>
            <CardHeader>
                <CardTitle>{{
                    t(
                        'i18n.components.manage_two_factor.two_factor_authentication',
                    )
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.components.manage_two_factor.manage_your_two_factor_authentication_settings',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent
                v-if="!twoFactorEnabled"
                class="flex flex-col items-start gap-4"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'i18n.components.manage_two_factor.when_two_factor_authentication_is_enabled_signing_in_requires',
                        )
                    }}
                </p>

                <Button v-if="hasSetupData" @click="showSetupModal = true">
                    <ShieldCheck />{{
                        t('i18n.components.manage_two_factor.continue_setup')
                    }}
                </Button>
                <Form
                    v-else
                    v-bind="enable.form()"
                    @success="showSetupModal = true"
                    #default="{ processing }"
                >
                    <Button type="submit" :disabled="processing">
                        {{
                            t(
                                'i18n.components.manage_two_factor.enable_two_factor_authentication',
                            )
                        }}
                    </Button>
                </Form>
            </CardContent>

            <CardContent v-else class="flex flex-col items-start gap-4">
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'i18n.components.manage_two_factor.signing_in_requires_an_additional_random_security_code_from',
                        )
                    }}
                </p>

                <Form v-bind="disable.form()" #default="{ processing }">
                    <Button
                        variant="destructive"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            t(
                                'i18n.components.manage_two_factor.disable_two_factor_authentication',
                            )
                        }}
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <TwoFactorRecoveryCodes v-if="twoFactorEnabled" />

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </div>
</template>
