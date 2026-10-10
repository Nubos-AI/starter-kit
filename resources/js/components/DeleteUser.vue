<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfilesController from '@/actions/App/Http/Controllers/Settings/ProfilesController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('i18n.components.delete_user.delete_account')"
            :description="
                t(
                    'i18n.components.delete_user.deletes_your_account_and_all_associated_data',
                )
            "
        />
        <div
            class="space-y-4 rounded-lg border border-danger-subtle bg-danger p-4"
        >
            <div class="relative space-y-0.5 text-danger">
                <p class="font-medium">
                    {{ t('i18n.components.delete_user.warning') }}
                </p>
                <p class="text-sm">
                    {{
                        t(
                            'i18n.components.delete_user.this_action_cannot_be_undone',
                        )
                    }}
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        data-test="delete-user-button"
                        >{{
                            t('i18n.components.delete_user.delete_account')
                        }}</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfilesController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>{{
                                t(
                                    'i18n.components.delete_user.are_you_sure_you_want_to_delete_your_account',
                                )
                            }}</DialogTitle>
                            <DialogDescription>
                                {{
                                    t(
                                        'i18n.components.delete_user.deleting_your_account_permanently_deletes_all_associated_data_enter',
                                    )
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">{{
                                t('i18n.components.delete_user.password')
                            }}</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                :placeholder="
                                    t('i18n.components.delete_user.password')
                                "
                            />
                            <InputError :message="errors.password" />
                            <InputError :message="errors.user" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{
                                        t('i18n.components.delete_user.cancel')
                                    }}
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                {{
                                    t(
                                        'i18n.components.delete_user.delete_account',
                                    )
                                }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
