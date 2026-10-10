import type { ComputedRef } from 'vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import UserPreferencesController from '@/actions/App/Http/Controllers/Preferences/UserPreferencesController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';
import type {
    ConfigurationGrid,
    GlobalPreferences,
    GridPreferences,
    ObjectTypePreferences,
    PreferenceCategory,
    PreferenceDocument,
    PreferencePatch,
    PreferencePolicy,
} from '@/types/preferences';
import { emptyObjectTypePreferences } from '@/types/preferences';

const PERSIST_DEBOUNCE_MS = 500;

const PERSIST_ERROR_MESSAGE =
    'Die Einstellung konnte nicht gespeichert werden.';

const SETTINGS_CATEGORY: Record<keyof GlobalPreferences, PreferenceCategory> = {
    appearance: 'layoutAndAppearance',
    density: 'layoutAndAppearance',
    pageSize: 'layoutAndAppearance',
    sidebarOpen: 'layoutAndAppearance',
    startObjectTypeId: 'layoutAndAppearance',
};

const OBJECT_TYPE_CATEGORY: Record<
    keyof ObjectTypePreferences,
    PreferenceCategory
> = {
    viewMode: 'viewMode',
    kanbanAxis: 'viewMode',
    kanbanPipeline: 'viewMode',
    hierarchyOrder: 'viewMode',
    columnState: 'columnsAndSorting',
    lastSegmentId: 'filterAndSegment',
    lastFilter: 'filterAndSegment',
    collapsedSections: 'panelState',
    hiddenSections: 'panelVisibility',
};

const GRID_CATEGORY: Record<keyof GridPreferences, PreferenceCategory> = {
    columnState: 'columnsAndSorting',
};

export interface UseUserPreferencesReturn {
    settings: ComputedRef<GlobalPreferences>;
    policy: ComputedRef<PreferencePolicy>;
    allows: (category: PreferenceCategory, area: string) => boolean;
    objectType: (objectTypeId: string) => ObjectTypePreferences;
    grid: (key: ConfigurationGrid) => GridPreferences;
    patch: (patch: PreferencePatch) => void;
    flush: () => Promise<void>;
}

function fallbackDocument(): PreferenceDocument {
    return {
        settings: {
            appearance: 'system',
            density: 'compact',
            pageSize: 100,
            sidebarOpen: true,
            startObjectTypeId: null,
        },
        objectTypes: {},
        grids: {},
        policy: {
            columnsAndSorting: { records: true, configuration: true },
            viewMode: { records: true },
            filterAndSegment: { records: false },
            layoutAndAppearance: { global: true },
            panelState: { records: true },
            panelVisibility: { records: true },
        },
    };
}

const stored = ref<PreferenceDocument>(fallbackDocument());

let pending: PreferencePatch = {};
let timer: ReturnType<typeof setTimeout> | null = null;

export function hydratePreferences(document: PreferenceDocument): void {
    stored.value = document;
    pending = {};

    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

export function syncPreferences(document: PreferenceDocument): void {
    stored.value = {
        ...document,
        objectTypes: { ...stored.value.objectTypes, ...document.objectTypes },
    };
}

export function hydrateObjectTypePreferences(
    objectTypeId: string,
    slice: ObjectTypePreferences,
): void {
    stored.value = {
        ...stored.value,
        objectTypes: { ...stored.value.objectTypes, [objectTypeId]: slice },
    };
}

function mergeKeyed<TKey extends string, TValue extends object>(
    target: Partial<Record<TKey, TValue>>,
    patch: Partial<Record<TKey, Partial<TValue>>>,
): Partial<Record<TKey, TValue>> {
    const merged: Partial<Record<TKey, TValue>> = { ...target };

    for (const key of Object.keys(patch) as TKey[]) {
        merged[key] = {
            ...(merged[key] ?? ({} as TValue)),
            ...(patch[key] ?? {}),
        } as TValue;
    }

    return merged;
}

function mergePatch(
    base: PreferencePatch,
    patch: PreferencePatch,
): PreferencePatch {
    const next: PreferencePatch = { ...base };

    if (patch.settings !== undefined) {
        next.settings = { ...(base.settings ?? {}), ...patch.settings };
    }

    if (patch.objectTypes !== undefined) {
        next.objectTypes = mergeKeyed(
            base.objectTypes ?? {},
            patch.objectTypes,
        ) as Record<string, Partial<ObjectTypePreferences>>;
    }

    if (patch.grids !== undefined) {
        next.grids = mergeKeyed(base.grids ?? {}, patch.grids);
    }

    return next;
}

function applyPatch(
    document: PreferenceDocument,
    patch: PreferencePatch,
): PreferenceDocument {
    const next: PreferenceDocument = { ...document };

    if (patch.settings !== undefined) {
        next.settings = { ...document.settings, ...patch.settings };
    }

    if (patch.objectTypes !== undefined) {
        next.objectTypes = mergeKeyed(
            document.objectTypes,
            patch.objectTypes,
        ) as Record<string, ObjectTypePreferences>;
    }

    if (patch.grids !== undefined) {
        next.grids = mergeKeyed(document.grids, patch.grids);
    }

    return next;
}

function allows(category: PreferenceCategory, area: string): boolean {
    return stored.value.policy[category]?.[area] ?? true;
}

/**
 * @param categories the preference-to-category map of the slice being filtered
 */
function permittedKeys(
    values: Record<string, unknown>,
    categories: Record<string, PreferenceCategory>,
    area: string,
): Record<string, unknown> {
    return Object.fromEntries(
        Object.entries(values).filter(([key]) => {
            const category = categories[key];

            return category === undefined || allows(category, area);
        }),
    );
}

function permittedEntries(
    slice: Record<string, Record<string, unknown>>,
    categories: Record<string, PreferenceCategory>,
    area: string,
): Record<string, Record<string, unknown>> {
    const kept: Record<string, Record<string, unknown>> = {};

    for (const [key, values] of Object.entries(slice)) {
        const permitted = permittedKeys(values, categories, area);

        if (Object.keys(permitted).length > 0) {
            kept[key] = permitted;
        }
    }

    return kept;
}

function permitted(patch: PreferencePatch): PreferencePatch {
    const next: PreferencePatch = {};

    if (patch.settings !== undefined) {
        const settings = permittedKeys(
            patch.settings as Record<string, unknown>,
            SETTINGS_CATEGORY,
            'global',
        );

        if (Object.keys(settings).length > 0) {
            next.settings = settings as Partial<GlobalPreferences>;
        }
    }

    if (patch.objectTypes !== undefined) {
        const objectTypes = permittedEntries(
            patch.objectTypes as Record<string, Record<string, unknown>>,
            OBJECT_TYPE_CATEGORY,
            'records',
        );

        if (Object.keys(objectTypes).length > 0) {
            next.objectTypes = objectTypes as Record<
                string,
                Partial<ObjectTypePreferences>
            >;
        }
    }

    if (patch.grids !== undefined) {
        const grids = permittedEntries(
            patch.grids as Record<string, Record<string, unknown>>,
            GRID_CATEGORY,
            'configuration',
        );

        if (Object.keys(grids).length > 0) {
            next.grids = grids as Partial<
                Record<ConfigurationGrid, Partial<GridPreferences>>
            >;
        }
    }

    return next;
}

function queue(patch: PreferencePatch): void {
    pending = mergePatch(pending, patch);
}

async function send(): Promise<void> {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    const payload = pending;
    pending = {};

    if (Object.keys(payload).length === 0) {
        return;
    }

    try {
        const response = await fetch(UserPreferencesController.update.url(), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: buildHeaders({ hasBody: true }),
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            toast.error(await readErrorReason(response, PERSIST_ERROR_MESSAGE));

            return;
        }

        stored.value = (await response.json()) as PreferenceDocument;
    } catch {
        toast.error(PERSIST_ERROR_MESSAGE);
    }
}

export function useUserPreferences(): UseUserPreferencesReturn {
    const patch = (values: PreferencePatch): void => {
        const travelling = permitted(values);

        stored.value = applyPatch(stored.value, values);

        if (Object.keys(travelling).length === 0) {
            return;
        }

        queue(travelling);

        if (timer !== null) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            void send();
        }, PERSIST_DEBOUNCE_MS);
    };

    return {
        settings: computed<GlobalPreferences>(() => stored.value.settings),
        policy: computed<PreferencePolicy>(() => stored.value.policy),
        allows,
        objectType: (objectTypeId) =>
            stored.value.objectTypes[objectTypeId] ??
            emptyObjectTypePreferences(),
        grid: (key) => stored.value.grids[key] ?? { columnState: null },
        patch,
        flush: send,
    };
}
