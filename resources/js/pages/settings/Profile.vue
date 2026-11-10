<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ProfilesController from '@/actions/App/Http/Controllers/Settings/ProfilesController';
import DeleteUser from '@/components/DeleteUser.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SalutationSelect from '@/components/SalutationSelect.vue';
import TextLink from '@/components/TextLink.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { edit as editProfile } from '@/routes/profile';
import { send } from '@/routes/verification';

const { t } = useI18n();

defineProps<{
    salutations: Array<{ value: string; label: string }>;
    pendingEmail?: string | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const salutation = ref<string>(user.value.salutation ?? '');
const firstName = ref<string>(user.value.first_name ?? '');
const lastName = ref<string>(user.value.last_name ?? '');
const email = ref<string>(user.value.email);
const currentPassword = ref<string>('');

const isEmailChanged = computed(
    () => email.value.trim().toLowerCase() !== user.value.email,
);

watch(
    () => page.props.status,
    (status) => {
        if (status !== 'verification-link-sent') {
            return;
        }

        toast.success(
            t(
                'i18n.pages.settings.profile.a_new_verification_link_has_been_sent_to_your',
            ),
        );
    },
    { immediate: true },
);

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({
        salutation: salutation.value,
        firstName: firstName.value,
        lastName: lastName.value,
        email: email.value,
    }),
    backHref: editProfile.url(),
});

function handleSaved(): void {
    currentPassword.value = '';
    email.value = user.value.email;
    markSaved();
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('i18n.pages.settings.profile.profile_settings')" />

        <Heading
            variant="small"
            :title="t('i18n.pages.settings.profile.profile')"
            :description="
                t(
                    'i18n.pages.settings.profile.change_your_name_and_email_address',
                )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.profile.personal_details')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.settings.profile.a_changed_email_address_must_be_verified_again',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ProfilesController.update.form()"
                    :on-success="handleSaved"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="salutation">{{
                                t('i18n.pages.settings.profile.salutation')
                            }}</Label>
                            <SalutationSelect
                                id="salutation"
                                v-model="salutation"
                                :options="salutations"
                            />
                            <InputError :message="errors.salutation" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">{{
                                t('i18n.pages.settings.profile.email_address')
                            }}</Label>
                            <Input
                                id="email"
                                v-model="email"
                                type="email"
                                name="email"
                                required
                                autocomplete="username"
                                :placeholder="
                                    t(
                                        'i18n.pages.settings.profile.email_address',
                                    )
                                "
                            />
                            <InputError :message="errors.email" />
                            <p
                                v-if="pendingEmail"
                                class="text-xs text-muted-foreground"
                                data-test="pending-email-hint"
                            >
                                {{
                                    t(
                                        'i18n.pages.settings.profile.pending_email_change',
                                        { value1: pendingEmail },
                                    )
                                }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="first_name">{{
                                t('i18n.pages.settings.profile.first_name')
                            }}</Label>
                            <Input
                                id="first_name"
                                v-model="firstName"
                                name="first_name"
                                required
                                autocomplete="given-name"
                                :placeholder="
                                    t('i18n.pages.settings.profile.first_name')
                                "
                            />
                            <InputError :message="errors.first_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="last_name">{{
                                t('i18n.pages.settings.profile.last_name')
                            }}</Label>
                            <Input
                                id="last_name"
                                v-model="lastName"
                                name="last_name"
                                required
                                autocomplete="family-name"
                                :placeholder="
                                    t('i18n.pages.settings.profile.last_name')
                                "
                            />
                            <InputError :message="errors.last_name" />
                        </div>
                    </div>

                    <div
                        v-if="isEmailChanged"
                        class="grid gap-2 sm:max-w-sm"
                        data-test="current-password-field"
                    >
                        <Label for="current_password">{{
                            t('i18n.pages.settings.profile.current_password')
                        }}</Label>
                        <Input
                            id="current_password"
                            v-model="currentPassword"
                            type="password"
                            name="current_password"
                            required
                            autocomplete="current-password"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.settings.profile.confirm_the_email_change_with_your_current_password',
                                )
                            }}
                        </p>
                        <InputError :message="errors.current_password" />
                    </div>

                    <div
                        v-if="
                            page.props.mustVerifyEmail &&
                            !user.email_verified_at
                        "
                        class="text-sm"
                        data-test="verification-hint"
                    >
                        <p class="text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.settings.profile.your_email_address_has_not_been_verified_yet',
                                )
                            }}
                            <TextLink :href="send()" as="button">
                                {{
                                    t(
                                        'i18n.pages.settings.profile.resend_verification_email',
                                    )
                                }}
                            </TextLink>
                        </p>
                    </div>

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </Form>
            </CardContent>
        </Card>

        <DeleteUser />

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
