<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, router } from '@inertiajs/vue3';
import { Check, Copy, RefreshCw, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import WebhookSubscriptionsController from '@/actions/App/Http/Controllers/Webhooks/WebhookSubscriptionsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MultiSelectOption } from '@/components/ui/multi-select';
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { resolveStatus, WEBHOOK_STATUS } from '@/lib/statusMaps';

const { t } = useI18n();

interface Option {
    value: string;
    label: string;
}

interface WebhookSubscriptionPayload {
    id: string;
    name: string;
    targetUrl: string;
    authUsername: string | null;
    status: string;
    eventTypes: string[];
    objectTypeId: string | null;
    roleName: string | null;
    consecutiveFailures: number;
    lastError: string | null;
    activatedAt: string | null;
    rotatedAt: string | null;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    subscription: WebhookSubscriptionPayload | null;
    secret: string | null;
    eventTypeOptions: Option[];
    objectTypeOptions: Option[];
    roleOptions: Option[];
}>();

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.webhooks.form.new_webhook')
        : (props.subscription?.name ?? t('i18n.pages.webhooks.form.webhook')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const ALL_OBJECT_TYPES = 'all';

const name = ref<string>(props.subscription?.name ?? '');
const targetUrl = ref<string>(props.subscription?.targetUrl ?? '');
const authUsername = ref<string>(props.subscription?.authUsername ?? '');
const authPassword = ref<string>('');
const roleName = ref<string>(props.roleOptions[0]?.value ?? '');
const objectTypeId = ref<string>(
    props.subscription?.objectTypeId ?? ALL_OBJECT_TYPES,
);
const eventTypes = ref<string[]>(props.subscription?.eventTypes ?? []);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        name: name.value,
        targetUrl: targetUrl.value,
        authUsername: authUsername.value,
        authPassword: authPassword.value,
        roleName: roleName.value,
        objectTypeId: objectTypeId.value,
        eventTypes: [...eventTypes.value].sort(),
    }),
    backHref: WebhookSubscriptionsController.index.url(),
});

const eventTypeChoices = computed<MultiSelectOption[]>(() =>
    props.eventTypeOptions.map((option) => ({
        value: option.value,
        label: option.label,
    })),
);

function withSelections(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        auth_username: authUsername.value.trim(),
        auth_password: authPassword.value,
        roleName: roleName.value,
        event_types: eventTypes.value,
        object_type_id:
            objectTypeId.value === ALL_OBJECT_TYPES ? null : objectTypeId.value,
    };
}

const dismissedSecret = ref<string | null>(null);
const copied = ref<boolean>(false);

const secret = computed<string | null>(() =>
    props.secret === dismissedSecret.value ? null : props.secret,
);
const rotateOpen = ref<boolean>(false);
const deleteOpen = ref<boolean>(false);

const isEdit = computed<boolean>(() => props.mode === 'edit');

const formAction = computed(() =>
    isEdit.value
        ? WebhookSubscriptionsController.update.form({
              subscription: props.subscription!.id,
          })
        : WebhookSubscriptionsController.store.form(),
);

const status = computed(() =>
    resolveStatus(WEBHOOK_STATUS, props.subscription?.status ?? 'pending'),
);

const isPending = computed<boolean>(
    () => props.subscription?.status === 'pending',
);

async function copySecret(): Promise<void> {
    if (secret.value === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(secret.value);
        copied.value = true;
    } catch {
        copied.value = false;
        toast.error(
            t('i18n.pages.webhooks.form.the_secret_could_not_be_copied'),
        );
    }
}

function closeSecret(): void {
    dismissedSecret.value = props.secret;
    copied.value = false;
}

function recheckChallenge(): void {
    router.post(
        WebhookSubscriptionsController.recheck.url({
            subscription: props.subscription!.id,
        }),
    );
}

function rotateSecret(): void {
    rotateOpen.value = false;

    router.post(
        WebhookSubscriptionsController.rotateSecret.url({
            subscription: props.subscription!.id,
        }),
    );
}

function deleteSubscription(): void {
    deleteOpen.value = false;

    router.delete(
        WebhookSubscriptionsController.destroy.url({
            subscription: props.subscription!.id,
        }),
    );
}
</script>

<template>
    <div class="flex flex-col gap-6 p-3">
        <Head
            :title="
                mode === 'create'
                    ? t('i18n.pages.webhooks.form.new_webhook')
                    : t('i18n.pages.webhooks.form.edit_webhook')
            "
        />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="heading"
                :description="
                    mode === 'create'
                        ? t(
                              'i18n.pages.webhooks.form.the_secret_is_shown_only_once_after_creation_store',
                          )
                        : t(
                              'i18n.pages.webhooks.form.edit_this_subscription_s_destination_events_and_filters',
                          )
                "
            />
            <Badge v-if="isEdit" :variant="status.variant">
                {{ status.label }}
            </Badge>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.webhooks.form.details')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.webhooks.form.each_delivery_is_signed_changing_the_destination_url_triggers',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="formAction"
                    :transform="withSelections"
                    :on-success="markSaved"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="webhook-name">{{
                            t('i18n.pages.webhooks.form.name')
                        }}</Label>
                        <Input
                            id="webhook-name"
                            v-model="name"
                            name="name"
                            type="text"
                            required
                            :placeholder="
                                t(
                                    'i18n.pages.webhooks.form.e_g_customer_portal',
                                )
                            "
                            data-webhook-name
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2 sm:max-w-xl">
                        <Label for="webhook-target-url">{{
                            t('i18n.pages.webhooks.form.destination_url')
                        }}</Label>
                        <Input
                            id="webhook-target-url"
                            v-model="targetUrl"
                            name="target_url"
                            type="url"
                            required
                            :placeholder="
                                t(
                                    'i18n.pages.webhooks.form.https_hooks_example_com_webhook',
                                )
                            "
                            data-webhook-target-url
                        />
                        <InputError :message="errors.target_url" />
                    </div>

                    <div class="grid gap-4 sm:max-w-xl sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="webhook-auth-username">
                                {{
                                    t(
                                        'i18n.pages.webhooks.form.basic_auth_username',
                                    )
                                }}
                            </Label>
                            <Input
                                id="webhook-auth-username"
                                v-model="authUsername"
                                name="auth_username"
                                type="text"
                                autocomplete="off"
                                :placeholder="
                                    t('i18n.pages.webhooks.form.optional')
                                "
                                data-webhook-auth-username
                            />
                            <InputError :message="errors.auth_username" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="webhook-auth-password">
                                {{
                                    t(
                                        'i18n.pages.webhooks.form.basic_auth_password',
                                    )
                                }}
                            </Label>
                            <Input
                                id="webhook-auth-password"
                                v-model="authPassword"
                                name="auth_password"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    isEdit &&
                                    subscription!.authUsername !== null
                                        ? t(
                                              'i18n.pages.webhooks.form.leave_unchanged',
                                          )
                                        : t('i18n.pages.webhooks.form.optional')
                                "
                                data-webhook-auth-password
                            />
                            <InputError :message="errors.auth_password" />
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground sm:max-w-xl">
                        {{
                            t(
                                'i18n.pages.webhooks.form.each_delivery_authenticates_with_a_username_and_password_using',
                            )
                        }}
                    </p>

                    <div v-if="!isEdit" class="grid gap-2 sm:max-w-sm">
                        <Label for="webhook-role">{{
                            t('i18n.pages.webhooks.form.role')
                        }}</Label>
                        <Select v-model="roleName">
                            <SelectTrigger id="webhook-role" data-webhook-role>
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.webhooks.form.select_role',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in props.roleOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.webhooks.form.the_webhook_s_service_user_receives_this_role_roles',
                                )
                            }}
                        </p>
                        <InputError :message="errors.roleName" />
                    </div>

                    <div v-else class="grid gap-2 sm:max-w-sm">
                        <Label>{{ t('i18n.pages.webhooks.form.role') }}</Label>
                        <p class="text-sm text-muted-foreground">
                            {{
                                subscription!.roleName ??
                                t('i18n.pages.webhooks.form.no_role')
                            }}
                        </p>
                    </div>

                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="webhook-object-type">
                            {{
                                t('i18n.pages.webhooks.form.object_type_filter')
                            }}
                        </Label>
                        <Select v-model="objectTypeId">
                            <SelectTrigger
                                id="webhook-object-type"
                                data-webhook-object-type
                            >
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.webhooks.form.all_object_types',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL_OBJECT_TYPES">
                                    {{
                                        t(
                                            'i18n.pages.webhooks.form.all_object_types',
                                        )
                                    }}
                                </SelectItem>
                                <SelectItem
                                    v-for="option in props.objectTypeOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.webhooks.form.optional_without_a_selection_all_object_types_are_sent',
                                )
                            }}
                        </p>
                        <InputError :message="errors.object_type_id" />
                    </div>

                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="webhook-event-types">{{
                            t('i18n.pages.webhooks.form.event_types')
                        }}</Label>
                        <MultiSelect
                            id="webhook-event-types"
                            v-model="eventTypes"
                            :options="eventTypeChoices"
                            :placeholder="
                                t('i18n.pages.webhooks.form.select_events')
                            "
                            :empty-label="
                                t(
                                    'i18n.pages.webhooks.form.no_events_available',
                                )
                            "
                            :aria-label="
                                t('i18n.pages.webhooks.form.event_types')
                            "
                        />
                        <InputError :message="errors.event_types" />
                    </div>

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </Form>
            </CardContent>
        </Card>

        <Card v-if="isEdit">
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.webhooks.form.delivery')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.webhooks.form.challenge_status_and_recent_delivery_attempts',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div
                    v-if="isPending"
                    role="status"
                    class="flex flex-col gap-2 rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                    data-webhook-pending-hint
                >
                    <p>
                        {{
                            t(
                                'i18n.pages.webhooks.form.the_destination_url_has_not_confirmed_the_challenge_yet',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            t(
                                'i18n.pages.webhooks.form.the_endpoint_must_accept_a_post_with_the_body',
                            )
                        }}
                        <code>{{
                            t('i18n.pages.webhooks.form.challenge')
                        }}</code>
                        {{
                            t(
                                'i18n.pages.webhooks.form.respond_with_2xx_and_return_the_same_value_under',
                            )
                        }}
                        <code>{{
                            t('i18n.pages.webhooks.form.challenge_2')
                        }}</code>
                        {{
                            t(
                                'i18n.pages.webhooks.form.an_arbitrary_address_such_as_a_home_page_does',
                            )
                        }}
                    </p>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div class="flex flex-col gap-1">
                        <dt class="text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.webhooks.form.consecutive_failures',
                                )
                            }}
                        </dt>
                        <dd>{{ subscription!.consecutiveFailures }}</dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="text-muted-foreground">
                            {{ t('i18n.pages.webhooks.form.activated_on') }}
                        </dt>
                        <dd>{{ subscription!.activatedAt ?? '—' }}</dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="text-muted-foreground">
                            {{
                                t('i18n.pages.webhooks.form.secret_rotated_on')
                            }}
                        </dt>
                        <dd>{{ subscription!.rotatedAt ?? '—' }}</dd>
                    </div>
                </dl>

                <p
                    v-if="subscription!.lastError !== null"
                    class="text-sm text-destructive"
                    data-webhook-last-error
                >
                    {{ subscription!.lastError }}
                </p>

                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        data-webhook-recheck
                        @click="recheckChallenge"
                    >
                        <RefreshCw class="size-4" />
                        {{ t('i18n.pages.webhooks.form.check_again') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        data-webhook-rotate
                        @click="rotateOpen = true"
                    >
                        <RotateCcw class="size-4" />
                        {{ t('i18n.pages.webhooks.form.rotate_secret') }}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        data-webhook-delete
                        @click="deleteOpen = true"
                    >
                        {{ t('i18n.pages.webhooks.form.delete_webhook') }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <Dialog :open="secret !== null" @update:open="closeSecret">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        t('i18n.pages.webhooks.form.copy_secret_now')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'i18n.pages.webhooks.form.this_secret_will_never_be_shown_again_once_you',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="flex min-w-0 items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 font-mono text-sm"
                        data-webhook-secret
                    >
                        {{ secret }}
                    </code>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="shrink-0"
                        :aria-label="
                            copied
                                ? t('i18n.pages.webhooks.form.copied')
                                : t('i18n.pages.webhooks.form.copy_secret')
                        "
                        data-webhook-copy
                        @click="copySecret"
                    >
                        <Check v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>

                <DialogFooter>
                    <Button type="button" @click="closeSecret">{{
                        t('i18n.pages.webhooks.form.done')
                    }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="rotateOpen"
            :title="t('i18n.pages.webhooks.form.rotate_secret_2')"
            :description="
                t(
                    'i18n.pages.webhooks.form.a_new_secret_is_generated_and_shown_once_it',
                )
            "
            :confirm-label="t('i18n.pages.webhooks.form.rotate')"
            @confirm="rotateSecret"
        />

        <ConfirmDialog
            v-model:open="deleteOpen"
            :title="t('i18n.pages.webhooks.form.delete_webhook_2')"
            :description="
                t(
                    'i18n.pages.webhooks.form.the_webhook_will_no_longer_receive_events_this_action',
                )
            "
            :confirm-label="t('i18n.pages.webhooks.form.delete')"
            variant="destructive"
            @confirm="deleteSubscription"
        />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
