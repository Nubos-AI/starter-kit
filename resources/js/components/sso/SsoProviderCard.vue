<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import FormSwitch from '@/components/FormSwitch.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';

defineProps<{
    hasClientSecret: boolean;
    callbackUrl: string;
    errors: Partial<Record<string, string>>;
    isTesting: boolean;
    resetsVerification: boolean;
}>();

const emit = defineEmits<{ test: [] }>();

const isEnabled = defineModel<boolean>('isEnabled', { required: true });
const issuerUrl = defineModel<string>('issuerUrl', { required: true });
const clientId = defineModel<string>('clientId', { required: true });
const clientSecret = defineModel<string>('clientSecret', { required: true });

const { t } = useI18n();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.components.sso.sso_provider_card.title')
            }}</CardTitle>
            <CardDescription>{{
                t('i18n.components.sso.sso_provider_card.description')
            }}</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-4">
            <FormSwitch
                id="is_enabled"
                v-model="isEnabled"
                :label="t('i18n.components.sso.sso_provider_card.is_enabled')"
                :error="errors.is_enabled"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="issuer_url">{{
                        t('i18n.components.sso.sso_provider_card.issuer_url')
                    }}</Label>
                    <Input
                        id="issuer_url"
                        v-model="issuerUrl"
                        type="url"
                        :placeholder="
                            t(
                                'i18n.components.sso.sso_provider_card.issuer_placeholder',
                            )
                        "
                    />
                    <InputError :message="errors.issuer_url" />
                </div>
                <div class="grid gap-2">
                    <Label for="client_id">{{
                        t('i18n.components.sso.sso_provider_card.client_id')
                    }}</Label>
                    <Input id="client_id" v-model="clientId" />
                    <InputError :message="errors.client_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="client_secret">{{
                        t('i18n.components.sso.sso_provider_card.client_secret')
                    }}</Label>
                    <Input
                        id="client_secret"
                        v-model="clientSecret"
                        type="password"
                        autocomplete="new-password"
                    />
                    <p
                        v-if="hasClientSecret"
                        class="text-xs text-muted-foreground"
                        data-sso-secret-hint
                    >
                        {{
                            t(
                                'i18n.components.sso.sso_provider_card.secret_set',
                            )
                        }}
                    </p>
                    <InputError :message="errors.client_secret" />
                </div>
            </div>

            <Alert v-if="resetsVerification" data-sso-credentials-reset-warning>
                <TriangleAlert />
                <AlertTitle>{{
                    t(
                        'i18n.components.sso.sso_provider_card.credentials_reset_title',
                    )
                }}</AlertTitle>
                <AlertDescription>{{
                    t(
                        'i18n.components.sso.sso_provider_card.credentials_reset_description',
                    )
                }}</AlertDescription>
            </Alert>

            <div class="grid gap-1">
                <span class="text-sm font-medium">{{
                    t('i18n.components.sso.sso_provider_card.callback_url')
                }}</span>
                <code
                    class="rounded-md bg-muted px-3 py-2 font-mono text-sm break-all"
                    data-sso-callback-url
                    >{{ callbackUrl }}</code
                >
                <p class="text-xs text-muted-foreground">
                    {{
                        t('i18n.components.sso.sso_provider_card.callback_hint')
                    }}
                </p>
            </div>

            <div class="grid gap-2">
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="isTesting || issuerUrl === ''"
                        data-sso-test
                        @click="emit('test')"
                    >
                        {{ t('i18n.components.sso.sso_provider_card.test') }}
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground" data-sso-test-hint>
                    {{ t('i18n.components.sso.sso_provider_card.test_hint') }}
                </p>
            </div>

            <slot />
        </CardContent>
    </Card>
</template>
