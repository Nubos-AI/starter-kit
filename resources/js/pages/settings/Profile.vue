<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfilesController from '@/actions/App/Http/Controllers/Settings/ProfilesController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import { send } from '@/routes/verification';

const { t } = useI18n();

defineProps<{
    salutations: Array<{ value: string; label: string }>;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="t('i18n.pages.settings.profile.profile_settings')" />

    <h1 class="sr-only">
        {{ t('i18n.pages.settings.profile.profile_settings') }}
    </h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="t('i18n.pages.settings.profile.profile')"
            :description="
                t(
                    'i18n.pages.settings.profile.change_your_name_and_email_address',
                )
            "
        />

        <Form
            v-bind="ProfilesController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="salutation">{{
                    t('i18n.pages.settings.profile.salutation')
                }}</Label>
                <Select name="salutation" :default-value="user.salutation">
                    <SelectTrigger id="salutation" class="w-full">
                        <SelectValue
                            :placeholder="
                                t(
                                    'i18n.pages.settings.profile.select_salutation',
                                )
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in salutations"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError class="mt-2" :message="errors.salutation" />
            </div>

            <div class="grid gap-2">
                <Label for="first_name">{{
                    t('i18n.pages.settings.profile.first_name')
                }}</Label>
                <Input
                    id="first_name"
                    class="mt-1 block w-full"
                    name="first_name"
                    :default-value="user.first_name"
                    required
                    autocomplete="given-name"
                    :placeholder="t('i18n.pages.settings.profile.first_name')"
                />
                <InputError class="mt-2" :message="errors.first_name" />
            </div>

            <div class="grid gap-2">
                <Label for="last_name">{{
                    t('i18n.pages.settings.profile.last_name')
                }}</Label>
                <Input
                    id="last_name"
                    class="mt-1 block w-full"
                    name="last_name"
                    :default-value="user.last_name"
                    required
                    autocomplete="family-name"
                    :placeholder="t('i18n.pages.settings.profile.last_name')"
                />
                <InputError class="mt-2" :message="errors.last_name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{
                    t('i18n.pages.settings.profile.email_address')
                }}</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    :placeholder="
                        t('i18n.pages.settings.profile.email_address')
                    "
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    {{
                        t(
                            'i18n.pages.settings.profile.your_email_address_has_not_been_verified_yet',
                        )
                    }}
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-subtlest underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                    >
                        {{
                            t(
                                'i18n.pages.settings.profile.resend_verification_email',
                            )
                        }}
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-success"
                >
                    {{
                        t(
                            'i18n.pages.settings.profile.a_new_verification_link_has_been_sent_to_your',
                        )
                    }}
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    >{{ t('i18n.pages.settings.profile.save') }}</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
