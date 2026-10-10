import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';

export type FlowNodeType = 'action' | 'wait' | 'branch' | 'fork' | 'merge';

export interface FlowNodeData {
    action_type?: string;
    config?: Record<string, unknown>;
    branchCount?: number;
}

export interface FlowNode {
    id: string;
    type: FlowNodeType;
    position: { x: number; y: number };
    data: FlowNodeData;
}

export interface FlowEdge {
    id: string;
    source: string;
    target: string;
    sourceHandle?: string;
}

export interface FlowConnection {
    source?: string | null;
    target?: string | null;
    sourceHandle?: string | null;
    targetHandle?: string | null;
}

export type TriggerLeadUnit = 'hour' | 'day' | 'week' | 'month';

export type TriggerLeadDirection = 'before' | 'after';

export interface TriggerLead {
    value: number;
    unit: TriggerLeadUnit;
    direction: TriggerLeadDirection;
}

export interface TriggerDeltaCondition {
    field_key: string;
    operator: string;
    from?: unknown;
    to?: unknown;
    value?: unknown;
    comparator?: string;
}

export interface TriggerDefinition {
    type: string;
    field_keys?: string[];
    secret?: string;
    has_secret?: boolean;
    date_field_key?: string;
    lead?: TriggerLead;
    expression?: string;
    goal_id?: string;
    threshold_percent?: number;
    aging_rule_id?: string;
    after_days?: number;
}

export interface TriggerDraft {
    type: string;
    fieldKeys: string[];
    secret: string;
    hasSecret: boolean;
    dateFieldKey: string;
    lead: TriggerLead;
    expression: string;
    goalId: string;
    thresholdPercent: number | null;
    agingRuleId: string;
    agingAfterDays: number | null;
}

export interface CanvasGraphNode {
    id: string;
    type: FlowNodeType;
    action_type?: string;
    config?: Record<string, unknown>;
    position: { x: number; y: number };
    next?: string;
    branch?: { true?: string; false?: string };
    branches?: string[];
}

export interface CanvasDefinition {
    trigger: TriggerDefinition;
    conditions?: FilterGroupNode;
    delta_conditions?: TriggerDeltaCondition[];
    graph: { entry: string; nodes: CanvasGraphNode[] };
}

export interface GraphLimits {
    maxNodes: number;
}

export interface FlowCanvas {
    nodes: Ref<FlowNode[]>;
    edges: Ref<FlowEdge[]>;
    trigger: Ref<TriggerDraft>;
    triggerConditions: Ref<FilterGroupNode | null>;
    triggerDeltaConditions: Ref<TriggerDeltaCondition[]>;
    limits: GraphLimits;
    exceedsNodeCap: ComputedRef<boolean>;
    addNode: (type: FlowNodeType, data?: FlowNodeData) => FlowNode;
    setActionType: (id: string, value: string) => void;
    updateNodeData: (id: string, patch: Partial<FlowNodeData>) => void;
    removeNode: (id: string) => void;
    connect: (connection: FlowConnection) => void;
    toDefinition: () => CanvasDefinition;
    fromDefinition: (definition: CanvasDefinition | null) => void;
}

const NODE_TYPES: readonly FlowNodeType[] = [
    'action',
    'wait',
    'branch',
    'fork',
    'merge',
];

const NODE_ID_SUFFIX = /^node_(\d+)$/;
const VERTICAL_GAP = 180;

const DEFAULT_LIMITS: GraphLimits = {
    maxNodes: 100,
};

const DEFAULT_LEAD: TriggerLead = {
    value: 1,
    unit: 'day',
    direction: 'before',
};

const LEAD_UNITS: readonly TriggerLeadUnit[] = ['hour', 'day', 'week', 'month'];

const LEAD_DIRECTIONS: readonly TriggerLeadDirection[] = ['before', 'after'];

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isFilterGroupNode(value: unknown): value is FilterGroupNode {
    return isRecord(value) && Array.isArray(value.conditions);
}

function toLead(value: unknown): TriggerLead | null {
    if (!isRecord(value)) {
        return null;
    }

    const { value: amount, unit, direction } = value;

    if (typeof amount !== 'number' || !Number.isFinite(amount) || amount < 0) {
        return null;
    }

    if (!LEAD_UNITS.includes(unit as TriggerLeadUnit)) {
        return null;
    }

    if (!LEAD_DIRECTIONS.includes(direction as TriggerLeadDirection)) {
        return null;
    }

    return {
        value: amount,
        unit: unit as TriggerLeadUnit,
        direction: direction as TriggerLeadDirection,
    };
}

function toAfterDays(value: unknown): number | null {
    const isNumeric =
        typeof value === 'number' ||
        (typeof value === 'string' && value.trim() !== '');

    if (!isNumeric) {
        return null;
    }

    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed >= 0 ? parsed : null;
}

function toDeltaConditions(value: unknown): TriggerDeltaCondition[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.filter(
        (entry): entry is TriggerDeltaCondition =>
            isRecord(entry) &&
            typeof entry.field_key === 'string' &&
            typeof entry.operator === 'string',
    );
}

function isFlowNodeType(value: unknown): value is FlowNodeType {
    return (
        typeof value === 'string' &&
        (NODE_TYPES as readonly string[]).includes(value)
    );
}

function forkOrder(handle: string | null | undefined): number {
    const parsed = Number(handle);

    return Number.isFinite(parsed) ? parsed : Number.MAX_SAFE_INTEGER;
}

export function emptyTriggerDraft(): TriggerDraft {
    return {
        type: '',
        fieldKeys: [],
        secret: '',
        hasSecret: false,
        dateFieldKey: '',
        lead: { ...DEFAULT_LEAD },
        expression: '',
        goalId: '',
        thresholdPercent: null,
        agingRuleId: '',
        agingAfterDays: null,
    };
}

export function serializeTrigger(draft: TriggerDraft): TriggerDefinition {
    const definition: TriggerDefinition = { type: draft.type };

    if (draft.type === 'field_change' && draft.fieldKeys.length > 0) {
        definition.field_keys = [...draft.fieldKeys];
    }

    if (draft.type === 'webhook' && draft.secret !== '') {
        definition.secret = draft.secret;
    }

    if (draft.type === 'date_based') {
        if (draft.dateFieldKey !== '') {
            definition.date_field_key = draft.dateFieldKey;
        }

        definition.lead = { ...draft.lead };
    }

    if (draft.type === 'cron' && draft.expression !== '') {
        definition.expression = draft.expression;
    }

    if (draft.type === 'goal_threshold') {
        if (draft.goalId !== '') {
            definition.goal_id = draft.goalId;
        }

        if (draft.thresholdPercent !== null) {
            definition.threshold_percent = draft.thresholdPercent;
        }
    }

    if (draft.type === 'aging_stage') {
        if (draft.agingRuleId !== '') {
            definition.aging_rule_id = draft.agingRuleId;
        }

        if (draft.agingAfterDays !== null) {
            definition.after_days = draft.agingAfterDays;
        }
    }

    return definition;
}

export function hydrateTrigger(raw: unknown): TriggerDraft {
    const draft = emptyTriggerDraft();

    if (!isRecord(raw)) {
        return draft;
    }

    if (typeof raw.type === 'string') {
        draft.type = raw.type;
    }

    if (Array.isArray(raw.field_keys)) {
        draft.fieldKeys = raw.field_keys.filter(
            (key): key is string => typeof key === 'string',
        );
    }

    if (typeof raw.secret === 'string') {
        draft.secret = raw.secret;
    }

    if (raw.has_secret === true) {
        draft.hasSecret = true;
    }

    if (typeof raw.date_field_key === 'string') {
        draft.dateFieldKey = raw.date_field_key;
    }

    const lead = toLead(raw.lead);

    if (lead !== null) {
        draft.lead = lead;
    }

    if (typeof raw.expression === 'string') {
        draft.expression = raw.expression;
    }

    if (typeof raw.goal_id === 'string') {
        draft.goalId = raw.goal_id;
    }

    if (
        typeof raw.threshold_percent === 'number' &&
        Number.isFinite(raw.threshold_percent)
    ) {
        draft.thresholdPercent = raw.threshold_percent;
    }

    if (typeof raw.aging_rule_id === 'string') {
        draft.agingRuleId = raw.aging_rule_id;
    }

    draft.agingAfterDays = toAfterDays(raw.after_days);

    return draft;
}

export function useFlowCanvas(
    limits: GraphLimits = DEFAULT_LIMITS,
): FlowCanvas {
    const nodes = ref<FlowNode[]>([]);
    const edges = ref<FlowEdge[]>([]);
    const trigger = ref<TriggerDraft>(emptyTriggerDraft());
    const triggerConditions = ref<FilterGroupNode | null>(null);
    const triggerDeltaConditions = ref<TriggerDeltaCondition[]>([]);

    let counter = 0;

    const exceedsNodeCap = computed<boolean>(
        () => nodes.value.length > limits.maxNodes,
    );

    function nextNodeId(): string {
        counter += 1;

        return `node_${counter}`;
    }

    function syncCounter(): void {
        counter = nodes.value.reduce((max, node) => {
            const match = NODE_ID_SUFFIX.exec(node.id);

            return match ? Math.max(max, Number(match[1])) : max;
        }, 0);
    }

    function reset(): void {
        nodes.value = [];
        edges.value = [];
        trigger.value = emptyTriggerDraft();
        triggerConditions.value = null;
        triggerDeltaConditions.value = [];
        counter = 0;
    }

    function addNode(type: FlowNodeType, data: FlowNodeData = {}): FlowNode {
        const anchor = nodes.value[nodes.value.length - 1];

        const node: FlowNode = {
            id: nextNodeId(),
            type,
            position: {
                x: anchor?.position.x ?? 0,
                y: (anchor?.position.y ?? -VERTICAL_GAP) + VERTICAL_GAP,
            },
            data: type === 'fork' ? { branchCount: 2, ...data } : { ...data },
        };

        nodes.value = [...nodes.value, node];

        if (anchor !== undefined && canAutoConnectFrom(anchor)) {
            connect({ source: anchor.id, target: node.id });
        }

        return node;
    }

    function canAutoConnectFrom(anchor: FlowNode): boolean {
        if (anchor.type === 'branch' || anchor.type === 'fork') {
            return false;
        }

        return outEdges(anchor.id).length === 0;
    }

    function updateNodeData(id: string, patch: Partial<FlowNodeData>): void {
        nodes.value = nodes.value.map((node) => {
            if (node.id !== id) {
                return node;
            }

            return { ...node, data: { ...node.data, ...patch } };
        });
    }

    function setActionType(id: string, value: string): void {
        updateNodeData(id, { action_type: value });
    }

    function removeNode(id: string): void {
        nodes.value = nodes.value.filter((node) => node.id !== id);
        edges.value = edges.value.filter(
            (edge) => edge.source !== id && edge.target !== id,
        );
    }

    function makeEdge(
        source: string,
        target: string,
        handle: string | undefined,
    ): FlowEdge {
        return {
            id: `e_${source}_${handle ?? 'next'}_${target}`,
            source,
            target,
            sourceHandle: handle,
        };
    }

    function connect(connection: FlowConnection): void {
        if (!connection.source || !connection.target) {
            return;
        }

        const handle = connection.sourceHandle ?? undefined;
        const edge = makeEdge(connection.source, connection.target, handle);

        if (edges.value.some((existing) => existing.id === edge.id)) {
            return;
        }

        edges.value = [...edges.value, edge];
    }

    function outEdges(nodeId: string): FlowEdge[] {
        return edges.value.filter((edge) => edge.source === nodeId);
    }

    function serializeNode(node: FlowNode): CanvasGraphNode {
        const type = node.type;
        const data: FlowNodeData = node.data ?? {};

        const serialized: CanvasGraphNode = {
            id: node.id,
            type,
            position: { x: node.position.x, y: node.position.y },
        };

        if (type === 'action' && typeof data.action_type === 'string') {
            serialized.action_type = data.action_type;
        }

        if (isRecord(data.config)) {
            serialized.config = data.config;
        }

        const out = outEdges(node.id);

        if (type === 'branch') {
            const branch: { true?: string; false?: string } = {};

            for (const edge of out) {
                if (edge.sourceHandle === 'true') {
                    branch.true = edge.target;
                } else if (edge.sourceHandle === 'false') {
                    branch.false = edge.target;
                }
            }

            serialized.branch = branch;
        } else if (type === 'fork') {
            serialized.branches = [...out]
                .sort(
                    (a, b) =>
                        forkOrder(a.sourceHandle) - forkOrder(b.sourceHandle),
                )
                .map((edge) => edge.target);
        } else {
            const first = out[0];

            if (first !== undefined) {
                serialized.next = first.target;
            }
        }

        return serialized;
    }

    function computeEntry(): string {
        const inDegree = new Map<string, number>();

        for (const node of nodes.value) {
            inDegree.set(node.id, 0);
        }

        for (const edge of edges.value) {
            if (inDegree.has(edge.target)) {
                inDegree.set(edge.target, (inDegree.get(edge.target) ?? 0) + 1);
            }
        }

        for (const node of nodes.value) {
            if ((inDegree.get(node.id) ?? 0) === 0) {
                return node.id;
            }
        }

        return nodes.value.length > 0 ? nodes.value[0].id : '';
    }

    function toDefinition(): CanvasDefinition {
        const definition: CanvasDefinition = {
            trigger: serializeTrigger(trigger.value),
            graph: {
                entry: computeEntry(),
                nodes: nodes.value.map((node) => serializeNode(node)),
            },
        };

        if (triggerConditions.value !== null) {
            definition.conditions = triggerConditions.value;
        }

        if (triggerDeltaConditions.value.length > 0) {
            definition.delta_conditions = [...triggerDeltaConditions.value];
        }

        return definition;
    }

    function fromDefinition(definition: CanvasDefinition | null): void {
        reset();

        if (!isRecord(definition)) {
            return;
        }

        trigger.value = hydrateTrigger(definition.trigger);

        if (isFilterGroupNode(definition.conditions)) {
            triggerConditions.value = definition.conditions;
        }

        triggerDeltaConditions.value = toDeltaConditions(
            definition.delta_conditions,
        );

        const graph = definition.graph;

        if (!isRecord(graph) || !Array.isArray(graph.nodes)) {
            return;
        }

        const hydratedNodes: FlowNode[] = [];
        const rawById = new Map<string, Record<string, unknown>>();

        graph.nodes.forEach((raw, index) => {
            if (!isRecord(raw) || typeof raw.id !== 'string') {
                return;
            }

            if (!isFlowNodeType(raw.type)) {
                return;
            }

            const rawPosition = raw.position;
            const position =
                isRecord(rawPosition) &&
                typeof rawPosition.x === 'number' &&
                typeof rawPosition.y === 'number'
                    ? { x: rawPosition.x, y: rawPosition.y }
                    : { x: 0, y: index * VERTICAL_GAP };

            const data: FlowNodeData = {};

            if (typeof raw.action_type === 'string') {
                data.action_type = raw.action_type;
            }

            if (isRecord(raw.config)) {
                data.config = raw.config;
            }

            if (raw.type === 'fork' && Array.isArray(raw.branches)) {
                data.branchCount = Math.max(2, raw.branches.length);
            }

            hydratedNodes.push({ id: raw.id, type: raw.type, position, data });
            rawById.set(raw.id, raw);
        });

        const ids = new Set(hydratedNodes.map((node) => node.id));
        const hydratedEdges: FlowEdge[] = [];

        for (const node of hydratedNodes) {
            const raw = rawById.get(node.id);

            if (raw === undefined) {
                continue;
            }

            if (node.type === 'branch') {
                const branch = raw.branch;

                if (isRecord(branch)) {
                    for (const key of ['true', 'false'] as const) {
                        const target = branch[key];

                        if (typeof target === 'string' && ids.has(target)) {
                            hydratedEdges.push(makeEdge(node.id, target, key));
                        }
                    }
                }
            } else if (node.type === 'fork') {
                const branches = raw.branches;

                if (Array.isArray(branches)) {
                    branches.forEach((target, index) => {
                        if (typeof target === 'string' && ids.has(target)) {
                            hydratedEdges.push(
                                makeEdge(node.id, target, String(index)),
                            );
                        }
                    });
                }
            } else {
                const next = raw.next;

                if (typeof next === 'string' && ids.has(next)) {
                    hydratedEdges.push(makeEdge(node.id, next, undefined));
                }
            }
        }

        nodes.value = hydratedNodes;
        edges.value = hydratedEdges;
        syncCounter();
    }

    return {
        nodes,
        edges,
        trigger,
        triggerConditions,
        triggerDeltaConditions,
        limits,
        exceedsNodeCap,
        addNode,
        setActionType,
        updateNodeData,
        removeNode,
        connect,
        toDefinition,
        fromDefinition,
    };
}
