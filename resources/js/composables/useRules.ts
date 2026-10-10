import type { Ref } from 'vue';
import { getCurrentScope, onScopeDispose, ref } from 'vue';
import {
    destroy as destroyAction,
    index as indexAction,
    preview as previewAction,
    store as storeAction,
    update as updateAction,
} from '@/actions/App/Http/Controllers/Notifications/NotificationRulesController';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';
import type { FieldDefinition } from '@/types/fields';

const REQUEST_TIMEOUT_MS = 15000;

const LOAD_ERROR_MESSAGE =
    'Die Regeln konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const ACTION_ERROR_MESSAGE =
    'Die Regel konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

const PREVIEW_ERROR_MESSAGE =
    'Die Vorschau konnte nicht ermittelt werden. Bitte versuchen Sie es erneut.';

export type RuleTriggerType =
    | 'date_based'
    | 'stage_change'
    | 'assignment'
    | 'field_change'
    | 'creation';

export type LeadStageUnit = 'day' | 'week' | 'month' | 'hour';

export interface LeadStage {
    value: number;
    unit: LeadStageUnit;
}

export interface DateBasedConfig {
    date_field_key: string;
    lead_stages: LeadStage[];
}

export interface FieldChangeConfig {
    watched_field_keys: string[];
}

export type RuleConfig = DateBasedConfig | FieldChangeConfig | null;

export interface ReminderActionConfig {
    subject: string;
    due_offset: number;
}

export interface RuleAction {
    notify: true;
    create_reminder: ReminderActionConfig | null;
}

export interface RuleScope {
    segment_id: string | null;
    filter_definition: FilterGroupNode | null;
}

export interface RuleInput {
    object_type_id: string;
    name: string;
    trigger_type: RuleTriggerType;
    config: RuleConfig;
    segment_id: string | null;
    filter_definition: FilterGroupNode | null;
    action: RuleAction;
    is_active: boolean;
}

export interface NotificationRuleItem {
    id: string;
    name: string;
    trigger_type?: RuleTriggerType;
    is_active?: boolean;
    [key: string]: unknown;
}

export interface PreviewScope {
    object_type_id: string;
    segment_id: string | null;
    filter_definition: FilterGroupNode | null;
}

export interface WatchFieldOption {
    key: string;
    label: string;
    disabled: boolean;
}

export interface UseRulesReturn {
    rules: Ref<NotificationRuleItem[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    previewCount: Ref<number | null>;
    previewApproximate: Ref<boolean>;
    previewLoading: Ref<boolean>;
    load: (objectTypeSlug: string) => Promise<void>;
    create: (input: RuleInput) => Promise<NotificationRuleItem | null>;
    update: (
        id: string,
        input: RuleInput,
    ) => Promise<NotificationRuleItem | null>;
    destroy: (id: string) => Promise<boolean>;
    toggleActive: (
        id: string,
        isActive: boolean,
    ) => Promise<NotificationRuleItem | null>;
    preview: (scope: PreviewScope) => Promise<void>;
}

export function isFieldEncrypted(field: FieldDefinition): boolean {
    return (field as { is_encrypted?: boolean }).is_encrypted === true;
}

export function availableWatchFields(
    fields: FieldDefinition[],
): WatchFieldOption[] {
    return fields.map((field) => ({
        key: field.key,
        label: field.label,
        disabled: isFieldEncrypted(field),
    }));
}

export function addLeadStage(list: LeadStage[]): LeadStage[] {
    return [...list, { value: 0, unit: 'day' }];
}

export function removeLeadStage(list: LeadStage[], index: number): LeadStage[] {
    return list.filter((_stage, position) => position !== index);
}

function normalizeRule(raw: unknown): NotificationRuleItem {
    const source = (raw ?? {}) as Record<string, unknown>;

    return {
        ...source,
        id: String(source.id ?? ''),
        name: String(source.name ?? ''),
    };
}

export function useRules(): UseRulesReturn {
    const rules = ref<NotificationRuleItem[]>([]);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);
    const previewCount = ref<number | null>(null);
    const previewApproximate = ref<boolean>(false);
    const previewLoading = ref<boolean>(false);

    const controllers = new Set<AbortController>();

    const request = async (
        url: string,
        init: RequestInit,
    ): Promise<Response> => {
        const controller = new AbortController();
        controllers.add(controller);
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            return await fetch(url, {
                credentials: 'same-origin',
                headers: buildHeaders({
                    hasBody: init.body !== undefined && init.body !== null,
                }),
                signal: controller.signal,
                ...init,
            });
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    };

    const load = async (objectTypeSlug: string): Promise<void> => {
        loading.value = true;

        try {
            const response = await request(
                indexAction.url({ objectType: objectTypeSlug }),
                { method: 'GET' },
            );

            if (!response.ok) {
                throw new Error(
                    `Rule index endpoint responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as { data?: unknown[] };

            rules.value = (payload.data ?? []).map(normalizeRule);
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            loading.value = false;
        }
    };

    const mutate = async (
        url: string,
        method: string,
        body: Record<string, unknown>,
    ): Promise<NotificationRuleItem | null> => {
        try {
            const response = await request(url, {
                method,
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return null;
            }

            error.value = null;

            const payload = (await response.json()) as { data?: unknown };

            return payload.data === undefined || payload.data === null
                ? null
                : normalizeRule(payload.data);
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return null;
        }
    };

    const create = (input: RuleInput): Promise<NotificationRuleItem | null> =>
        mutate(storeAction.url(), 'POST', { ...input });

    const update = (
        id: string,
        input: RuleInput,
    ): Promise<NotificationRuleItem | null> =>
        mutate(updateAction.url({ rule: id }), 'PUT', { ...input });

    const toggleActive = (
        id: string,
        isActive: boolean,
    ): Promise<NotificationRuleItem | null> =>
        mutate(updateAction.url({ rule: id }), 'PUT', { is_active: isActive });

    const destroy = async (id: string): Promise<boolean> => {
        try {
            const response = await request(destroyAction.url({ rule: id }), {
                method: 'DELETE',
            });

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return false;
            }

            error.value = null;

            return true;
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return false;
        }
    };

    const preview = async (scope: PreviewScope): Promise<void> => {
        previewLoading.value = true;

        try {
            const response = await request(previewAction.url(), {
                method: 'POST',
                body: JSON.stringify(scope),
            });

            if (!response.ok) {
                throw new Error(
                    `Rule preview endpoint responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as {
                count?: number;
                approximate?: boolean;
            };

            previewCount.value = payload.count ?? 0;
            previewApproximate.value = payload.approximate === true;
            error.value = null;
        } catch {
            error.value = PREVIEW_ERROR_MESSAGE;
        } finally {
            previewLoading.value = false;
        }
    };

    if (getCurrentScope()) {
        onScopeDispose(() => {
            for (const controller of controllers) {
                controller.abort();
            }

            controllers.clear();
        });
    }

    return {
        rules,
        loading,
        error,
        previewCount,
        previewApproximate,
        previewLoading,
        load,
        create,
        update,
        destroy,
        toggleActive,
        preview,
    };
}
