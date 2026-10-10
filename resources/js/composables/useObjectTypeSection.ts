import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { usePermissions } from '@/composables/usePermissions';
import type { ObjectTypeSectionKey } from '@/lib/objectTypeSections';
import { OBJECT_TYPE_SECTIONS } from '@/lib/objectTypeSections';
import type { ObjectTypeSummary } from '@/types/objectTypes';

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
            ? [{ title: objectType.name }]
            : [
                  {
                      title: objectType.name,
                      href: ObjectTypesController.edit({
                          objectType: objectType.slug,
                      }),
                  },
                  {
                      title:
                          typeof section === 'string'
                              ? OBJECT_TYPE_SECTIONS[section]
                              : section.title,
                  },
              ];
    });

    const { can } = usePermissions();

    const canUpdate = computed<boolean>(() =>
        can(
            objectType === null ? 'object-types.create' : 'object-types.update',
        ),
    );
    const isEditable = computed<boolean>(
        () => canUpdate.value && objectType?.is_system !== true,
    );

    return { canUpdate, isEditable };
}
