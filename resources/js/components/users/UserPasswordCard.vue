<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import UserPasswordsController from '@/actions/App/Http/Controllers/Users/UserPasswordsController';
import FormActions from '@/components/FormActions.vue';
import InputError from '@/components/InputError.vue';
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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

const { t } = useI18n();

const props = defineProps<{
    userId: string;
    canReceiveResetLink: boolean;
    backHref: string;
}>();

const password = ref<string>('');
const passwordConfirmation = ref<string>('');
const resetLinkPending = ref<boolean>(false);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        password: password.value,
        passwordConfirmation: passwordConfirmation.value,
    }),
    backHref: () => props.backHref,
});

const resetLinkReason = computed<string | undefined>(() =>
    props.canReceiveResetLink
        ? undefined
        : t(
              'i18n.components.users.user_password_card.a_reset_link_can_only_be_sent_to_users',
          ),
);

function onSaved(): void {
    password.value = '';
    passwordConfirmation.value = '';
    markSaved();
}

function sendResetLink(): void {
    resetLinkPending.value = true;

    router.post(
        UserPasswordsController.sendResetLink.url({ user: props.userId }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resetLinkPending.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="contents">
        <Card data-user-password-card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.components.users.user_password_card.password')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.components.users.user_password_card.a_new_password_signs_this_user_out_everywhere_they',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        UserPasswordsController.update.form({ user: userId })
                    "
                    :on-success="onSaved"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="user-password">{{
                                t(
                                    'i18n.components.users.user_password_card.new_password',
                                )
                            }}</Label>
                            <Input
                                id="user-password"
                                v-model="password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                :aria-invalid="Boolean(errors.password)"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="user-password-confirmation">
                                {{
                                    t(
                                        'i18n.components.users.user_password_card.confirm_password',
                                    )
                                }}
                            </Label>
                            <Input
                                id="user-password-confirmation"
                                v-model="passwordConfirmation"
                                name="password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                :aria-invalid="
                                    Boolean(errors.password_confirmation)
                                "
                            />
                            <InputError
                                :message="errors.password_confirmation"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <TooltipProvider :delay-duration="0">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            data-send-reset-link
                                            :disabled="
                                                !canReceiveResetLink ||
                                                resetLinkPending
                                            "
                                            @click="sendResetLink"
                                        >
                                            {{
                                                t(
                                                    'i18n.components.users.user_password_card.send_reset_link',
                                                )
                                            }}
                                        </Button>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent
                                    v-if="resetLinkReason !== undefined"
                                >
                                    {{ resetLinkReason }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>

                        <FormActions
                            :dirty="isDirty"
                            :processing="processing"
                            @cancel="requestLeave"
                        />
                    </div>
                </Form>
            </CardContent>
        </Card>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
