import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import { OBJECT_TYPE_SECTIONS } from '@/domains/business-data/data';
import type { ObjectTypeSummary } from '@/domains/business-data/data';
import type { ObjectTypeSectionKey } from '@/domains/business-data/data';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { usePermissions } from '@/shared/composables/usePermissions';

const CREATE_TITLE = 'Neuer Objekttyp';

export interface UseObjectTypeSectionReturn {
  canUpdate: ComputedRef<boolean>;
  isEditable: ComputedRef<boolean>;
}

export function useObjectTypeSection(
  objectType: ObjectTypeSummary | null,
  section: ObjectTypeSectionKey | { title: string },
): UseObjectTypeSectionReturn {
  usePageBreadcrumbs(() => {
    if (objectType === null) {
      return [{ title: CREATE_TITLE }];
    }

    return section === 'details'
      ? [{ title: objectType.label }]
      : [
          {
            title: objectType.label,
            href: ObjectTypesController.edit({
              objectType: objectType.slug,
            }),
          },
          {
            title: typeof section === 'string' ? OBJECT_TYPE_SECTIONS[section] : section.title,
          },
        ];
  });

  const { can } = usePermissions();

  const canUpdate = computed<boolean>(() => can(objectType === null ? 'object-types.create' : 'object-types.update'));
  const isEditable = computed<boolean>(() => canUpdate.value && objectType?.is_system !== true);

  return { canUpdate, isEditable };
}
