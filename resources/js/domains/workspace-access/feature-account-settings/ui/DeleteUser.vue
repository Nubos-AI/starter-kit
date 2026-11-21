<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfilesController from '@/actions/App/Http/Controllers/Settings/ProfilesController';
import { PasswordInput } from '@/domains/workspace-access/ui-auth';
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/primitives/dialog';

const { t } = useI18n();

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('i18n.components.delete_user.delete_account') }}</CardTitle>
      <CardDescription>
        {{ t('i18n.components.delete_user.deletes_your_account_and_all_associated_data') }}
      </CardDescription>
    </CardHeader>
    <CardContent class="flex flex-col items-start gap-4">
      <div class="grid gap-1 self-stretch rounded-lg border border-danger-subtle bg-danger p-4 text-danger">
        <p class="font-medium">
          {{ t('i18n.components.delete_user.warning') }}
        </p>
        <p class="text-sm">
          {{ t('i18n.components.delete_user.this_action_cannot_be_undone') }}
        </p>
      </div>
      <Dialog>
        <DialogTrigger as-child>
          <Button variant="destructive" data-test="delete-user-button">{{
            t('i18n.components.delete_user.delete_account')
          }}</Button>
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
                t('i18n.components.delete_user.are_you_sure_you_want_to_delete_your_account')
              }}</DialogTitle>
              <DialogDescription>
                {{
                  t('i18n.components.delete_user.deleting_your_account_permanently_deletes_all_associated_data_enter')
                }}
              </DialogDescription>
            </DialogHeader>

            <FormField
              for="password"
              hide-label
              :label="t('i18n.components.delete_user.password')"
              :error="errors.user ?? errors.password"
            >
              <PasswordInput
                id="password"
                name="password"
                ref="passwordInput"
                :placeholder="t('i18n.components.delete_user.password')"
              />
            </FormField>

            <DialogFooter class="gap-2">
              <DialogClose as-child>
                <Button
                  variant="outline"
                  @click="
                    () => {
                      clearErrors();
                      reset();
                    }
                  "
                >
                  {{ t('i18n.components.delete_user.cancel') }}
                </Button>
              </DialogClose>

              <Button type="submit" variant="destructive" :disabled="processing" data-test="confirm-delete-user-button">
                {{ t('i18n.components.delete_user.delete_account') }}
              </Button>
            </DialogFooter>
          </Form>
        </DialogContent>
      </Dialog>
    </CardContent>
  </Card>
</template>
