<script setup lang="ts">
import { KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import type { Passkey } from '@/types/auth';

const { t } = useI18n();

const props = defineProps<{
    passkey: Passkey;
}>();

const emit = defineEmits<{
    remove: [id: number, onError: () => void];
}>();

const isDeleting = ref(false);

const handleDelete = () => {
    isDeleting.value = true;
    emit('remove', props.passkey.id, () => {
        isDeleting.value = false;
    });
};
</script>

<template>
    <div class="flex items-center justify-between border-b p-4 last:border-b-0">
        <div class="flex items-center gap-4">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
            >
                <KeyRound class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-1">
                <div class="flex items-center gap-2.5">
                    <p class="font-medium tracking-tight">{{ passkey.name }}</p>
                    <span
                        v-if="passkey.authenticator"
                        class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset"
                    >
                        {{ passkey.authenticator }}
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ t('i18n.components.passkey_item.created') }}
                    {{ passkey.created_at_diff }}
                    <template v-if="passkey.last_used_at_diff">
                        <span class="mx-1 text-muted-foreground/50">/</span>
                        {{ t('i18n.components.passkey_item.last_used') }}
                        {{ passkey.last_used_at_diff }}
                    </template>
                </p>
            </div>
        </div>

        <Dialog>
            <DialogTrigger as-child>
                <Button
                    variant="plain"
                    size="icon-sm"
                    class="icon-danger hover:icon-danger-hovered"
                >
                    <Trash2 class="h-4 w-4" />
                    <span class="sr-only">{{
                        t('i18n.components.passkey_item.remove')
                    }}</span>
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogTitle>{{
                    t('i18n.components.passkey_item.remove_passkey')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'i18n.components.passkey_item.are_you_sure_you_want_to_remove_the_passkey',
                        )
                    }}{{ passkey.name
                    }}{{
                        t(
                            'i18n.components.passkey_item.you_will_no_longer_be_able_to_use_it',
                        )
                    }}
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{
                            t('i18n.components.passkey_item.cancel')
                        }}</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="isDeleting"
                        @click="handleDelete"
                    >
                        {{
                            isDeleting
                                ? t('i18n.components.passkey_item.removing')
                                : t(
                                      'i18n.components.passkey_item.remove_passkey',
                                  )
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
