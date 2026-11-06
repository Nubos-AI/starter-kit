<script setup lang="ts">
import { ref } from 'vue';
import ApiTokenSecretDialog from '@/components/api/ApiTokenSecretDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import type { ScimSettings } from '@/types/sso';

const props = defineProps<{
    scim: ScimSettings;
    secret: string | null;
    isRotating: boolean;
}>();

const emit = defineEmits<{ rotate: [] }>();

const { t } = useI18n();

const confirmOpen = ref<boolean>(false);

function requestRotation(): void {
    if (!props.scim.hasToken) {
        emit('rotate');

        return;
    }

    confirmOpen.value = true;
}

function confirmRotation(): void {
    confirmOpen.value = false;
    emit('rotate');
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.components.sso.scim_provisioning_card.title')
            }}</CardTitle>
            <CardDescription>{{
                t('i18n.components.sso.scim_provisioning_card.description')
            }}</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-4">
            <div class="grid gap-1">
                <span class="text-sm font-medium">{{
                    t('i18n.components.sso.scim_provisioning_card.base_url')
                }}</span>
                <code
                    class="rounded-md bg-muted px-3 py-2 font-mono text-sm break-all"
                    data-scim-base-url
                    >{{ scim.baseUrl }}</code
                >
            </div>

            <div class="grid gap-1" data-scim-token-state>
                <span class="text-sm font-medium">{{
                    t('i18n.components.sso.scim_provisioning_card.token')
                }}</span>
                <p class="text-sm text-muted-foreground">
                    <template v-if="scim.hasToken">
                        {{
                            t(
                                'i18n.components.sso.scim_provisioning_card.token_created',
                                { value1: formatDateTime(scim.tokenCreatedAt) },
                            )
                        }}
                        {{
                            scim.lastUsedAt === null
                                ? t(
                                      'i18n.components.sso.scim_provisioning_card.never_used',
                                  )
                                : t(
                                      'i18n.components.sso.scim_provisioning_card.last_used',
                                      {
                                          value1: formatDateTime(
                                              scim.lastUsedAt,
                                          ),
                                      },
                                  )
                        }}
                    </template>
                    <template v-else>{{
                        t('i18n.components.sso.scim_provisioning_card.no_token')
                    }}</template>
                </p>
            </div>

            <div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="isRotating"
                    data-scim-rotate
                    @click="requestRotation"
                >
                    {{
                        scim.hasToken
                            ? t(
                                  'i18n.components.sso.scim_provisioning_card.rotate_token',
                              )
                            : t(
                                  'i18n.components.sso.scim_provisioning_card.create_token',
                              )
                    }}
                </Button>
            </div>

            <ConfirmDialog
                v-model:open="confirmOpen"
                :title="
                    t('i18n.components.sso.scim_provisioning_card.rotate_title')
                "
                :description="
                    t(
                        'i18n.components.sso.scim_provisioning_card.rotate_description',
                    )
                "
                :confirm-label="
                    t(
                        'i18n.components.sso.scim_provisioning_card.rotate_confirm',
                    )
                "
                variant="destructive"
                :pending="isRotating"
                @confirm="confirmRotation"
            />

            <ApiTokenSecretDialog :secret="secret" />
        </CardContent>
    </Card>
</template>
