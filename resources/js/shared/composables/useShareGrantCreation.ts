import { ref } from 'vue';
import type { Ref } from 'vue';

export interface ShareGrantPayload<Grantee extends string> {
  grantee_type: Grantee;
  grantee_id: string;
  can_edit: boolean;
}

export interface UseShareGrantCreationReturn<Grantee extends string> {
  canEdit: Ref<boolean>;
  submitting: Ref<boolean>;
  submit: (granteeType: Grantee, granteeId: string | null) => Promise<void>;
}

export function useShareGrantCreation<Grantee extends string>(
  create: (payload: ShareGrantPayload<Grantee>) => Promise<unknown>,
): UseShareGrantCreationReturn<Grantee> {
  const canEdit = ref<boolean>(false);
  const submitting = ref<boolean>(false);

  async function submit(granteeType: Grantee, granteeId: string | null): Promise<void> {
    if (granteeId === null || granteeId === '' || submitting.value) {
      return;
    }

    submitting.value = true;

    try {
      const created = await create({
        grantee_type: granteeType,
        grantee_id: granteeId,
        can_edit: canEdit.value,
      });

      if (created !== null) {
        canEdit.value = false;
      }
    } finally {
      submitting.value = false;
    }
  }

  return { canEdit, submitting, submit };
}
