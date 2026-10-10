<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ApiTokensController from '@/actions/App/Http/Controllers/Settings/ApiTokensController';
import ApiTokenSecretDialog from '@/components/api/ApiTokenSecretDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { IconActionButton } from '@/components/ui/icon-action-button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

interface PersonalApiTokenRow {
    id: string;
    name: string;
    abilities: string[];
    lastUsedAt: string | null;
    expiresAt: string | null;
    createdAt: string | null;
}

const props = withDefaults(
    defineProps<{
        tokens?: PersonalApiTokenRow[];
        abilityOptions?: SelectOption[];
        secret?: string | null;
    }>(),
    {
        tokens: () => [],
        abilityOptions: () => [],
        secret: null,
    },
);

const name = ref<string>('');
const abilities = ref<string[]>([]);
const expiresAt = ref<string>('');
const creating = ref<boolean>(false);
const errors = ref<Record<string, string>>({});

const pendingRotate = ref<PersonalApiTokenRow | null>(null);
const pendingRevoke = ref<PersonalApiTokenRow | null>(null);
const actionPending = ref<boolean>(false);

const abilityLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.abilityOptions.map((option) => [option.value, option.label]),
    ),
);

const canCreate = computed<boolean>(
    () => name.value.trim() !== '' && abilities.value.length > 0,
);

const rotateDialogOpen = computed<boolean>({
    get: () => pendingRotate.value !== null,
    set: (value) => {
        if (!value) {
            pendingRotate.value = null;
        }
    },
});

const revokeDialogOpen = computed<boolean>({
    get: () => pendingRevoke.value !== null,
    set: (value) => {
        if (!value) {
            pendingRevoke.value = null;
        }
    },
});

const rotateDescription = computed<string>(() =>
    pendingRotate.value === null
        ? ''
        : t(
              'i18n.pages.settings.api_tokens.a_new_secret_will_be_generated_for_and_shown',
              { value1: pendingRotate.value.name },
          ),
);

const revokeDescription = computed<string>(() =>
    pendingRevoke.value === null
        ? ''
        : t('i18n.pages.settings.api_tokens.will_stop_working_immediately', {
              value1: pendingRevoke.value.name,
          }),
);

function abilityLabel(ability: string): string {
    return abilityLabels.value[ability] ?? ability;
}

function createToken(): void {
    creating.value = true;
    errors.value = {};

    router.post(
        ApiTokensController.store.url(),
        {
            name: name.value,
            abilities: abilities.value,
            expiresAt: expiresAt.value === '' ? null : expiresAt.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                name.value = '';
                abilities.value = [];
                expiresAt.value = '';
            },
            onError: (received) => {
                errors.value = received;
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
}

function confirmRotate(): void {
    const row = pendingRotate.value;

    if (row === null) {
        return;
    }

    actionPending.value = true;

    router.post(
        ApiTokensController.rotate.url({ token: row.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                actionPending.value = false;
                pendingRotate.value = null;
            },
        },
    );
}

function confirmRevoke(): void {
    const row = pendingRevoke.value;

    if (row === null) {
        return;
    }

    actionPending.value = true;

    router.delete(ApiTokensController.destroy.url({ token: row.id }), {
        preserveScroll: true,
        onFinish: () => {
            actionPending.value = false;
            pendingRevoke.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('i18n.pages.settings.api_tokens.api_token')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.settings.api_tokens.api_token')"
            :description="
                t(
                    'i18n.pages.settings.api_tokens.personal_tokens_for_the_rest_api_a_token_has',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.api_tokens.new_token')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.api_tokens.the_secret_is_shown_only_once_after_creation',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="token_name">{{
                        t('i18n.pages.settings.api_tokens.name')
                    }}</Label>
                    <Input
                        id="token_name"
                        v-model="name"
                        :placeholder="
                            t('i18n.pages.settings.api_tokens.e_g_laptop_cli')
                        "
                        data-testid="token-name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="token_abilities">{{
                        t('i18n.pages.settings.api_tokens.permissions')
                    }}</Label>
                    <MultiSelect
                        id="token_abilities"
                        v-model="abilities"
                        :options="props.abilityOptions"
                        :placeholder="
                            t(
                                'i18n.pages.settings.api_tokens.select_permissions',
                            )
                        "
                        data-testid="token-abilities"
                    />
                    <InputError :message="errors.abilities" />
                </div>

                <div class="grid gap-2">
                    <Label for="token_expires">{{
                        t('i18n.pages.settings.api_tokens.expires_optional')
                    }}</Label>
                    <Input
                        id="token_expires"
                        v-model="expiresAt"
                        type="datetime-local"
                        data-testid="token-expires"
                    />
                    <InputError :message="errors.expiresAt" />
                </div>

                <div class="flex justify-end">
                    <Button
                        type="button"
                        :disabled="!canCreate || creating"
                        data-testid="token-create"
                        @click="createToken"
                    >
                        {{ t('i18n.pages.settings.api_tokens.create_token') }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.api_tokens.my_tokens')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.api_tokens.a_rotated_token_keeps_its_name_and_permissions_but',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="props.tokens.length === 0"
                    class="text-sm text-muted-foreground"
                    data-testid="token-empty"
                >
                    {{
                        t(
                            'i18n.pages.settings.api_tokens.you_have_not_created_a_personal_token_yet',
                        )
                    }}
                </p>

                <ul v-else class="divide-y" data-testid="token-list">
                    <li
                        v-for="token in props.tokens"
                        :key="token.id"
                        class="flex items-center justify-between gap-4 py-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ token.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    token.abilities.map(abilityLabel).join(', ')
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'i18n.pages.settings.api_tokens.last_used',
                                    )
                                }}
                                {{ formatDateTime(token.lastUsedAt) }}
                                {{
                                    t('i18n.pages.settings.api_tokens.expires')
                                }}
                                {{ formatDateTime(token.expiresAt) }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <IconActionButton
                                :icon="RotateCcw"
                                :label="
                                    t('i18n.pages.settings.api_tokens.rotate')
                                "
                                :data-testid="`rotate-${token.id}`"
                                @click="pendingRotate = token"
                            />
                            <IconActionButton
                                :icon="Trash2"
                                :label="
                                    t('i18n.pages.settings.api_tokens.revoke')
                                "
                                variant="destructive"
                                :data-testid="`revoke-${token.id}`"
                                @click="pendingRevoke = token"
                            />
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <ApiTokenSecretDialog :secret="props.secret" />

        <ConfirmDialog
            v-model:open="rotateDialogOpen"
            :title="t('i18n.pages.settings.api_tokens.rotate_token')"
            :description="rotateDescription"
            :confirm-label="t('i18n.pages.settings.api_tokens.rotate')"
            :pending="actionPending"
            @confirm="confirmRotate"
        />

        <ConfirmDialog
            v-model:open="revokeDialogOpen"
            :title="t('i18n.pages.settings.api_tokens.revoke_token')"
            :description="revokeDescription"
            :confirm-label="t('i18n.pages.settings.api_tokens.revoke')"
            variant="destructive"
            :pending="actionPending"
            @confirm="confirmRevoke"
        />
    </div>
</template>
