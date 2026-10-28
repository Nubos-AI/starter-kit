import { describe, expect, it } from 'vitest';
import { useFlowCanvas } from '@/composables/useFlowCanvas';

const ACTION_TYPE_VALUES = [
    'SetField',
    'CreateRecord',
    'CreateReminderTask',
    'LinkRelation',
    'TransitionStage',
    'SendNotification',
    'HttpRequest',
    'Branch',
    'Delay',
    'SubFlow',
] as const;

const NODE_TYPE_VALUES = ['action', 'wait', 'branch', 'fork', 'merge'] as const;

const NODE_ID_PATTERN = /^[a-z][a-z0-9_]*$/;

interface CanvasNode {
    id: string;
    type: string;
    action_type?: string;
    config?: Record<string, unknown>;
    position?: { x: number; y: number };
    next?: string;
    branch?: { true?: string; false?: string };
    branches?: string[];
}

interface CanvasDefinition {
    trigger: {
        type: string;
        field_keys?: string[];
        goal_id?: string;
        threshold_percent?: number;
        aging_rule_id?: string;
        after_days?: number;
    };
    graph: { entry: string; nodes: CanvasNode[] };
}

function seedFromDefinition(
    def: CanvasDefinition,
): ReturnType<typeof useFlowCanvas> {
    const canvas = useFlowCanvas();
    canvas.fromDefinition(def as unknown as never);

    return canvas;
}

function serialize(canvas: ReturnType<typeof useFlowCanvas>): CanvasDefinition {
    return canvas.toDefinition() as unknown as CanvasDefinition;
}

function nodeById(def: CanvasDefinition, id: string): CanvasNode {
    const node = def.graph.nodes.find((candidate) => candidate.id === id);

    if (node === undefined) {
        throw new Error(`node ${id} missing from serialized graph`);
    }

    return node;
}

function normalize(def: CanvasDefinition): CanvasDefinition {
    return {
        ...def,
        graph: {
            ...def.graph,
            nodes: [...def.graph.nodes].sort((a, b) =>
                a.id.localeCompare(b.id),
            ),
        },
    };
}

const LINEAR_DEFINITION: CanvasDefinition = {
    trigger: { type: 'field_change', field_keys: ['stage'] },
    graph: {
        entry: 'node_1',
        nodes: [
            {
                id: 'node_1',
                type: 'action',
                action_type: 'SetField',
                position: { x: 0, y: 0 },
                next: 'node_2',
            },
            {
                id: 'node_2',
                type: 'wait',
                config: { seconds: 60 },
                position: { x: 0, y: 120 },
                next: 'node_3',
            },
            {
                id: 'node_3',
                type: 'action',
                action_type: 'SendNotification',
                position: { x: 0, y: 240 },
            },
        ],
    },
};

const BRANCH_DEFINITION: CanvasDefinition = {
    trigger: { type: 'field_change', field_keys: ['stage'] },
    graph: {
        entry: 'node_1',
        nodes: [
            {
                id: 'node_1',
                type: 'branch',
                position: { x: 0, y: 0 },
                branch: { true: 'node_2', false: 'node_3' },
            },
            {
                id: 'node_2',
                type: 'action',
                action_type: 'SendNotification',
                position: { x: -120, y: 120 },
            },
            {
                id: 'node_3',
                type: 'action',
                action_type: 'SetField',
                position: { x: 120, y: 120 },
            },
        ],
    },
};

const FORK_MERGE_DEFINITION: CanvasDefinition = {
    trigger: { type: 'stage_change' },
    graph: {
        entry: 'node_1',
        nodes: [
            {
                id: 'node_1',
                type: 'fork',
                position: { x: 0, y: 0 },
                branches: ['node_2', 'node_3'],
            },
            {
                id: 'node_2',
                type: 'action',
                action_type: 'SetField',
                position: { x: -120, y: 120 },
                next: 'node_4',
            },
            {
                id: 'node_3',
                type: 'action',
                action_type: 'SendNotification',
                position: { x: 120, y: 120 },
                next: 'node_4',
            },
            {
                id: 'node_4',
                type: 'merge',
                position: { x: 0, y: 240 },
                next: 'node_5',
            },
            {
                id: 'node_5',
                type: 'action',
                action_type: 'CreateRecord',
                position: { x: 0, y: 360 },
            },
        ],
    },
};

describe('useFlowCanvas — linear graph round-trip', () => {
    it('round-trips an action → wait → action chain losslessly', () => {
        const def = serialize(seedFromDefinition(LINEAR_DEFINITION));

        expect(def.graph.entry).toBe('node_1');

        for (const node of def.graph.nodes) {
            expect(node.id).toMatch(NODE_ID_PATTERN);
            expect(NODE_TYPE_VALUES).toContain(node.type);
        }

        expect(normalize(def)).toEqual(normalize(LINEAR_DEFINITION));
    });
});

describe('useFlowCanvas — conditional branch round-trip', () => {
    it('round-trips a branch:{true,false} graph without data loss', () => {
        const def = serialize(seedFromDefinition(BRANCH_DEFINITION));

        const branch = nodeById(def, 'node_1');
        expect(branch.type).toBe('branch');
        expect(branch.branch).toEqual({ true: 'node_2', false: 'node_3' });

        expect(normalize(def)).toEqual(normalize(BRANCH_DEFINITION));
    });
});

describe('useFlowCanvas — fork + merge diamond (key regression guard)', () => {
    it('serializes the fork entry, ordered branches and the merge next', () => {
        const def = serialize(seedFromDefinition(FORK_MERGE_DEFINITION));

        expect(def.graph.entry).toBe('node_1');

        const fork = nodeById(def, 'node_1');
        expect(fork.type).toBe('fork');
        expect(fork.branches).toEqual(['node_2', 'node_3']);

        const merge = nodeById(def, 'node_4');
        expect(merge.type).toBe('merge');
        expect(merge.next).toBe('node_5');

        for (const node of def.graph.nodes) {
            expect(node.id).toMatch(NODE_ID_PATTERN);
            expect(NODE_TYPE_VALUES).toContain(node.type);

            if (node.type === 'action') {
                expect(ACTION_TYPE_VALUES).toContain(node.action_type);
            }
        }
    });

    it('round-trips the diamond preserving ordered branches and the merge', () => {
        const def = serialize(seedFromDefinition(FORK_MERGE_DEFINITION));

        expect(normalize(def)).toEqual(normalize(FORK_MERGE_DEFINITION));

        const rehydrated = serialize(seedFromDefinition(def));
        expect(nodeById(rehydrated, 'node_1').branches).toEqual([
            'node_2',
            'node_3',
        ]);
        expect(normalize(rehydrated)).toEqual(normalize(FORK_MERGE_DEFINITION));
    });
});

describe('useFlowCanvas — node positions', () => {
    it('preserves each node position:{x,y} across the round-trip', () => {
        const def = serialize(seedFromDefinition(FORK_MERGE_DEFINITION));

        for (const source of FORK_MERGE_DEFINITION.graph.nodes) {
            expect(nodeById(def, source.id).position).toEqual(source.position);
        }
    });
});

describe('useFlowCanvas — webhook trigger secret', () => {
    it('carries the secret through a definition round-trip', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: { type: 'webhook', secret: 'top-secret' },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.secret).toBe('top-secret');
        expect(canvas.toDefinition().trigger.secret).toBe('top-secret');
    });

    it('omits the secret key entirely when none is set', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = { ...canvas.trigger.value, type: 'webhook' };

        expect('secret' in canvas.toDefinition().trigger).toBe(false);
    });

    it('clears a previously hydrated secret on the next definition', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: { type: 'webhook', secret: 'top-secret' },
            graph: { entry: '', nodes: [] },
        } as unknown as never);
        canvas.fromDefinition({
            trigger: { type: 'record_created' },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.secret).toBe('');
    });

    it('keeps the marker that a secret is stored without ever seeing it', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: { type: 'webhook', has_secret: true },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.hasSecret).toBe(true);
        expect(canvas.trigger.value.secret).toBe('');
    });

    it('sends no secret key back when the stored one was left untouched', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: { type: 'webhook', has_secret: true },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        const trigger = canvas.toDefinition().trigger;

        expect('secret' in trigger).toBe(false);
        expect('has_secret' in trigger).toBe(false);
    });

    it('sends the typed secret when one replaces the stored one', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: { type: 'webhook', has_secret: true },
            graph: { entry: '', nodes: [] },
        } as unknown as never);
        canvas.trigger.value = { ...canvas.trigger.value, secret: 'replaced' };

        expect(canvas.toDefinition().trigger.secret).toBe('replaced');
    });

    it('treats a missing marker as no stored secret', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: { type: 'webhook' },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.hasSecret).toBe(false);
    });

    it('ignores a non-string secret', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: { type: 'webhook', secret: 42 },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.secret).toBe('');
    });
});

describe('useFlowCanvas — trigger configuration', () => {
    it('serializes watched field keys only for the field change trigger', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = { ...canvas.trigger.value, type: 'assignment' };
        canvas.trigger.value = {
            ...canvas.trigger.value,
            fieldKeys: ['stage'],
        };

        expect('field_keys' in canvas.toDefinition().trigger).toBe(false);

        canvas.trigger.value = {
            ...canvas.trigger.value,
            type: 'field_change',
        };

        expect(canvas.toDefinition().trigger.field_keys).toEqual(['stage']);
    });

    it('carries the date field key and lead through a round-trip', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: {
                type: 'date_based',
                date_field_key: 'due_date',
                lead: { value: 3, unit: 'day', direction: 'before' },
            },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.dateFieldKey).toBe('due_date');
        expect(canvas.trigger.value.lead).toEqual({
            value: 3,
            unit: 'day',
            direction: 'before',
        });

        const trigger = canvas.toDefinition().trigger;

        expect(trigger.date_field_key).toBe('due_date');
        expect(trigger.lead).toEqual({
            value: 3,
            unit: 'day',
            direction: 'before',
        });
    });

    it('carries the cron expression and its conditions through a round-trip', () => {
        const canvas = useFlowCanvas();
        const conditions = {
            combinator: 'and',
            conditions: [{ field: 'stage', operator: 'equals', value: 'won' }],
        };

        canvas.fromDefinition({
            trigger: { type: 'cron', expression: '0 7 * * 1' },
            conditions,
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.expression).toBe('0 7 * * 1');

        const definition = canvas.toDefinition();

        expect(definition.trigger.expression).toBe('0 7 * * 1');
        expect(definition.conditions).toEqual(conditions);
    });

    it('keeps conditions and delta conditions at the definition top level', () => {
        const canvas = useFlowCanvas();
        const conditions = {
            combinator: 'and',
            conditions: [{ field: 'stage', operator: 'equals', value: 'won' }],
        };
        const deltaConditions = [
            { field_key: 'stage', operator: 'changedTo', to: 'won' },
        ];

        canvas.fromDefinition({
            trigger: { type: 'field_change', field_keys: ['stage'] },
            conditions,
            delta_conditions: deltaConditions,
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        const definition = canvas.toDefinition();

        expect(definition.conditions).toEqual(conditions);
        expect(definition.delta_conditions).toEqual(deltaConditions);
    });

    it('drops keys that do not belong to the selected trigger type', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: {
                type: 'cron',
                expression: '0 7 * * 1',
                secret: 'top-secret',
                date_field_key: 'due_date',
            },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        const trigger = canvas.toDefinition().trigger;

        expect('secret' in trigger).toBe(false);
        expect('date_field_key' in trigger).toBe(false);
    });
});

describe('useFlowCanvas — degenerate input', () => {
    it('hydrates an empty canvas from null without throwing', () => {
        const canvas = useFlowCanvas();

        expect(() =>
            canvas.fromDefinition(null as unknown as never),
        ).not.toThrow();
        expect((canvas.nodes.value as unknown[]).length).toBe(0);
        expect((canvas.edges.value as unknown[]).length).toBe(0);
    });

    it('tolerates a malformed/partial definition and yields an empty canvas', () => {
        const canvas = useFlowCanvas();

        const malformed = {
            trigger: { type: 42 },
            graph: { nodes: 'not-an-array' },
        };

        expect(() =>
            canvas.fromDefinition(malformed as unknown as never),
        ).not.toThrow();
        expect((canvas.nodes.value as unknown[]).length).toBe(0);
        expect((canvas.edges.value as unknown[]).length).toBe(0);
    });
});

describe('useFlowCanvas — advisory node cap mirror', () => {
    it('flags exceedsNodeCap only once the node count passes maxNodes', () => {
        const canvas = useFlowCanvas({ maxNodes: 1 });

        expect(canvas.exceedsNodeCap.value).toBe(false);

        canvas.addNode('action');
        expect(canvas.exceedsNodeCap.value).toBe(false);

        canvas.addNode('action');
        expect(canvas.exceedsNodeCap.value).toBe(true);
    });
});

describe('useFlowCanvas — placement of added nodes', () => {
    it('places new nodes far enough apart for their handles to be draggable', () => {
        const canvas = useFlowCanvas();

        const first = canvas.addNode('action');
        const second = canvas.addNode('wait');
        const third = canvas.addNode('action');

        expect(first.position).toEqual({ x: 0, y: 0 });
        expect(second.position.y - first.position.y).toBeGreaterThanOrEqual(
            160,
        );
        expect(third.position.y - second.position.y).toBeGreaterThanOrEqual(
            160,
        );
    });

    it('connects each new node to the previous one so the graph stays reachable', () => {
        const canvas = useFlowCanvas();

        const first = canvas.addNode('action');
        const second = canvas.addNode('wait');
        const third = canvas.addNode('action');

        expect(canvas.edges.value).toHaveLength(2);
        expect(
            canvas.edges.value.some(
                (edge) => edge.source === first.id && edge.target === second.id,
            ),
        ).toBe(true);
        expect(
            canvas.edges.value.some(
                (edge) => edge.source === second.id && edge.target === third.id,
            ),
        ).toBe(true);
    });

    it('leaves the very first node unconnected', () => {
        const canvas = useFlowCanvas();

        canvas.addNode('action');

        expect(canvas.edges.value).toHaveLength(0);
    });

    it('does not auto-connect when the previous node is a branch, because a branch needs a labelled edge', () => {
        const canvas = useFlowCanvas();

        canvas.addNode('action');
        canvas.addNode('branch');
        canvas.addNode('action');

        expect(canvas.edges.value).toHaveLength(1);
    });

    it('does not auto-connect when the previous node is a fork', () => {
        const canvas = useFlowCanvas();

        canvas.addNode('action');
        canvas.addNode('fork');
        canvas.addNode('action');

        expect(canvas.edges.value).toHaveLength(1);
    });

    it('never gives the anchor node a second outgoing edge', () => {
        const canvas = useFlowCanvas();

        const first = canvas.addNode('action');
        const second = canvas.addNode('action');

        canvas.connect({ source: second.id, target: first.id });

        canvas.addNode('action');

        const outgoingOfSecond = canvas.edges.value.filter(
            (edge) => edge.source === second.id,
        );

        expect(outgoingOfSecond).toHaveLength(1);
    });
});

describe('useFlowCanvas — removeNode prunes the node and its edges', () => {
    it('drops the node plus every edge that touches it', () => {
        const canvas = useFlowCanvas();
        const first = canvas.addNode('action', { action_type: 'SetField' });
        const second = canvas.addNode('wait');
        const third = canvas.addNode('action', {
            action_type: 'SendNotification',
        });

        canvas.connect({ source: first.id, target: second.id });
        canvas.connect({ source: second.id, target: third.id });

        canvas.removeNode(second.id);

        expect(canvas.nodes.value.map((node) => node.id)).toEqual([
            first.id,
            third.id,
        ]);
        expect(
            canvas.edges.value.some(
                (edge) =>
                    edge.source === second.id || edge.target === second.id,
            ),
        ).toBe(false);
    });
});

describe('useFlowCanvas — updateNodeData merges the data bag', () => {
    it('merges the patch into an existing node without dropping other keys', () => {
        const canvas = useFlowCanvas();
        const action = canvas.addNode('action', { action_type: 'SetField' });

        canvas.updateNodeData(action.id, {
            config: { message: { title: 'Hallo' } },
        });

        const updated = canvas.nodes.value.find(
            (node) => node.id === action.id,
        );

        expect(updated?.data.action_type).toBe('SetField');
        expect(updated?.data.config).toEqual({ message: { title: 'Hallo' } });
    });

    it('leaves unrelated nodes untouched', () => {
        const canvas = useFlowCanvas();
        const first = canvas.addNode('fork');
        const second = canvas.addNode('wait');

        canvas.updateNodeData(second.id, { config: { seconds: 30 } });

        expect(
            canvas.nodes.value.find((node) => node.id === first.id)?.data
                .branchCount,
        ).toBe(2);
        expect(
            canvas.nodes.value.find((node) => node.id === second.id)?.data
                .config,
        ).toEqual({ seconds: 30 });
    });
});

const GOAL_ID = '01JZ0000000000000000000001';
const OTHER_GOAL_ID = '01JZ0000000000000000000002';

const GOAL_THRESHOLD_DEFINITION: CanvasDefinition = {
    trigger: {
        type: 'goal_threshold',
        goal_id: GOAL_ID,
        threshold_percent: 90,
    },
    graph: {
        entry: 'node_1',
        nodes: [
            {
                id: 'node_1',
                type: 'action',
                action_type: 'SendNotification',
                position: { x: 0, y: 0 },
            },
        ],
    },
};

describe('useFlowCanvas — goal threshold trigger', () => {
    it('serializes the goal and the threshold as a number', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = {
            ...canvas.trigger.value,
            type: 'goal_threshold',
        };
        canvas.trigger.value = { ...canvas.trigger.value, goalId: GOAL_ID };
        canvas.trigger.value = {
            ...canvas.trigger.value,
            thresholdPercent: 90,
        };

        const trigger = canvas.toDefinition().trigger;

        expect(trigger.goal_id).toBe(GOAL_ID);
        expect(trigger.threshold_percent).toBe(90);
        expect(typeof trigger.threshold_percent).toBe('number');
    });

    it('keeps a fractional threshold intact', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = {
            ...canvas.trigger.value,
            type: 'goal_threshold',
        };
        canvas.trigger.value = {
            ...canvas.trigger.value,
            thresholdPercent: 0.5,
        };

        expect(canvas.toDefinition().trigger.threshold_percent).toBe(0.5);
    });

    it('omits both keys entirely while nothing is configured', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = {
            ...canvas.trigger.value,
            type: 'goal_threshold',
        };

        const trigger = canvas.toDefinition().trigger;

        expect('goal_id' in trigger).toBe(false);
        expect('threshold_percent' in trigger).toBe(false);
    });

    it('hydrates the goal and the threshold from an existing definition', () => {
        const canvas = useFlowCanvas();
        canvas.fromDefinition(GOAL_THRESHOLD_DEFINITION as unknown as never);

        expect(canvas.trigger.value.type).toBe('goal_threshold');
        expect(canvas.trigger.value.goalId).toBe(GOAL_ID);
        expect(canvas.trigger.value.thresholdPercent).toBe(90);
    });

    it('round-trips a goal threshold definition losslessly', () => {
        const def = serialize(seedFromDefinition(GOAL_THRESHOLD_DEFINITION));

        expect(normalize(def)).toEqual(normalize(GOAL_THRESHOLD_DEFINITION));

        const rehydrated = serialize(seedFromDefinition(def));

        expect(normalize(rehydrated)).toEqual(
            normalize(GOAL_THRESHOLD_DEFINITION),
        );
    });

    it('drops both keys once another trigger type is selected', () => {
        const canvas = useFlowCanvas();
        canvas.fromDefinition(GOAL_THRESHOLD_DEFINITION as unknown as never);

        canvas.trigger.value = { ...canvas.trigger.value, type: 'cron' };

        const trigger = canvas.toDefinition().trigger;

        expect('goal_id' in trigger).toBe(false);
        expect('threshold_percent' in trigger).toBe(false);
    });

    it('clears a previously hydrated goal and threshold on the next definition', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition(GOAL_THRESHOLD_DEFINITION as unknown as never);
        canvas.fromDefinition({
            trigger: { type: 'record_created' },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.goalId).toBe('');
        expect(canvas.trigger.value.thresholdPercent).toBeNull();
    });

    it('ignores a non-string goal and a non-numeric threshold', () => {
        const canvas = useFlowCanvas();

        canvas.fromDefinition({
            trigger: {
                type: 'goal_threshold',
                goal_id: 42,
                threshold_percent: 'neunzig',
            },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.goalId).toBe('');
        expect(canvas.trigger.value.thresholdPercent).toBeNull();
    });

    it('makes the serialized definition differ once the goal or the threshold changes', () => {
        const canvas = useFlowCanvas();
        canvas.fromDefinition(GOAL_THRESHOLD_DEFINITION as unknown as never);

        const baseline = JSON.stringify(canvas.toDefinition());

        canvas.trigger.value = {
            ...canvas.trigger.value,
            thresholdPercent: 75,
        };
        expect(JSON.stringify(canvas.toDefinition())).not.toBe(baseline);

        canvas.trigger.value = {
            ...canvas.trigger.value,
            thresholdPercent: 90,
        };
        expect(JSON.stringify(canvas.toDefinition())).toBe(baseline);

        canvas.trigger.value = {
            ...canvas.trigger.value,
            goalId: OTHER_GOAL_ID,
        };
        expect(JSON.stringify(canvas.toDefinition())).not.toBe(baseline);
    });
});

describe('useFlowCanvas — fork branches follow handle order', () => {
    it('orders fork.branches by numeric source handle, not edge insertion order', () => {
        const canvas = useFlowCanvas();
        const fork = canvas.addNode('fork');
        const first = canvas.addNode('action', { action_type: 'SetField' });
        const second = canvas.addNode('action', {
            action_type: 'SendNotification',
        });

        canvas.connect({
            source: fork.id,
            target: second.id,
            sourceHandle: '1',
        });
        canvas.connect({
            source: fork.id,
            target: first.id,
            sourceHandle: '0',
        });

        const def = serialize(canvas);

        expect(nodeById(def, fork.id).branches).toEqual([first.id, second.id]);
    });
});

const AGING_RULE_ID = '01JZ0000000000000000000011';
const OTHER_AGING_RULE_ID = '01JZ0000000000000000000012';

const AGING_STAGE_DEFINITION: CanvasDefinition = {
    trigger: {
        type: 'aging_stage',
        aging_rule_id: AGING_RULE_ID,
        after_days: 7,
    },
    graph: {
        entry: 'node_1',
        nodes: [
            {
                id: 'node_1',
                type: 'action',
                action_type: 'SendNotification',
                position: { x: 0, y: 0 },
            },
        ],
    },
};

const IMMEDIATE_AGING_STAGE_DEFINITION: CanvasDefinition = {
    ...AGING_STAGE_DEFINITION,
    trigger: {
        type: 'aging_stage',
        aging_rule_id: AGING_RULE_ID,
        after_days: 0,
    },
};

function seedFromRawTrigger(
    trigger: Record<string, unknown>,
): ReturnType<typeof useFlowCanvas> {
    const canvas = useFlowCanvas();
    canvas.fromDefinition({
        trigger,
        graph: { entry: '', nodes: [] },
    } as unknown as never);

    return canvas;
}

describe('useFlowCanvas — aging stage trigger', () => {
    it('serializes the aging rule and the stage as a number', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = { ...canvas.trigger.value, type: 'aging_stage' };
        canvas.trigger.value = {
            ...canvas.trigger.value,
            agingRuleId: AGING_RULE_ID,
        };
        canvas.trigger.value = { ...canvas.trigger.value, agingAfterDays: 7 };

        const trigger = serialize(canvas).trigger;

        expect(trigger.aging_rule_id).toBe(AGING_RULE_ID);
        expect(trigger.after_days).toBe(7);
        expect(typeof trigger.after_days).toBe('number');
    });

    it('serializes the immediate stage instead of dropping it', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = { ...canvas.trigger.value, type: 'aging_stage' };
        canvas.trigger.value = {
            ...canvas.trigger.value,
            agingRuleId: AGING_RULE_ID,
        };
        canvas.trigger.value = { ...canvas.trigger.value, agingAfterDays: 0 };

        const trigger = serialize(canvas).trigger;

        expect('after_days' in trigger).toBe(true);
        expect(trigger.after_days).toBe(0);
    });

    it('omits both keys entirely while nothing is configured', () => {
        const canvas = useFlowCanvas();
        canvas.trigger.value = { ...canvas.trigger.value, type: 'aging_stage' };

        const trigger = serialize(canvas).trigger;

        expect('aging_rule_id' in trigger).toBe(false);
        expect('after_days' in trigger).toBe(false);
    });

    it('hydrates the aging rule and the stage from an existing definition', () => {
        const canvas = seedFromDefinition(AGING_STAGE_DEFINITION);

        expect(canvas.trigger.value.type).toBe('aging_stage');
        expect(canvas.trigger.value.agingRuleId).toBe(AGING_RULE_ID);
        expect(canvas.trigger.value.agingAfterDays).toBe(7);
    });

    it('hydrates the immediate stage as zero, not as an empty value', () => {
        const canvas = seedFromDefinition(IMMEDIATE_AGING_STAGE_DEFINITION);

        expect(canvas.trigger.value.agingAfterDays).toBe(0);
    });

    it('round-trips an aging stage definition losslessly', () => {
        const def = serialize(seedFromDefinition(AGING_STAGE_DEFINITION));

        expect(normalize(def)).toEqual(normalize(AGING_STAGE_DEFINITION));

        const rehydrated = serialize(seedFromDefinition(def));

        expect(normalize(rehydrated)).toEqual(
            normalize(AGING_STAGE_DEFINITION),
        );
    });

    it('round-trips the immediate stage losslessly', () => {
        const def = serialize(
            seedFromDefinition(IMMEDIATE_AGING_STAGE_DEFINITION),
        );

        expect(normalize(def)).toEqual(
            normalize(IMMEDIATE_AGING_STAGE_DEFINITION),
        );

        const rehydrated = serialize(seedFromDefinition(def));

        expect(normalize(rehydrated)).toEqual(
            normalize(IMMEDIATE_AGING_STAGE_DEFINITION),
        );
    });

    it('drops both keys once another trigger type is selected', () => {
        const canvas = seedFromDefinition(AGING_STAGE_DEFINITION);

        canvas.trigger.value = { ...canvas.trigger.value, type: 'cron' };

        const trigger = serialize(canvas).trigger;

        expect('aging_rule_id' in trigger).toBe(false);
        expect('after_days' in trigger).toBe(false);
    });

    it('clears a previously hydrated rule and stage on the next definition', () => {
        const canvas = seedFromDefinition(AGING_STAGE_DEFINITION);

        canvas.fromDefinition({
            trigger: { type: 'record_created' },
            graph: { entry: '', nodes: [] },
        } as unknown as never);

        expect(canvas.trigger.value.agingRuleId).toBe('');
        expect(canvas.trigger.value.agingAfterDays).toBeNull();
    });

    it('ignores a non-string aging rule reference', () => {
        const canvas = seedFromRawTrigger({
            type: 'aging_stage',
            aging_rule_id: 42,
            after_days: 7,
        });

        expect(canvas.trigger.value.agingRuleId).toBe('');
    });

    it('ignores a stage that is not a whole number of days', () => {
        for (const afterDays of ['sieben', 7.5, -1, true, null]) {
            const canvas = seedFromRawTrigger({
                type: 'aging_stage',
                aging_rule_id: AGING_RULE_ID,
                after_days: afterDays,
            });

            expect(canvas.trigger.value.agingAfterDays).toBeNull();
        }
    });

    it('ignores a blank stage instead of hydrating it as the immediate stage', () => {
        for (const afterDays of ['', '   ']) {
            const canvas = seedFromRawTrigger({
                type: 'aging_stage',
                aging_rule_id: AGING_RULE_ID,
                after_days: afterDays,
            });

            expect(canvas.trigger.value.agingAfterDays).toBeNull();

            const trigger = serialize(canvas).trigger;

            expect('after_days' in trigger).toBe(false);
        }
    });

    it('normalizes a numeric string stage into a number', () => {
        const canvas = seedFromRawTrigger({
            type: 'aging_stage',
            aging_rule_id: AGING_RULE_ID,
            after_days: '7',
        });

        expect(canvas.trigger.value.agingAfterDays).toBe(7);

        const trigger = serialize(canvas).trigger;

        expect(trigger.after_days).toBe(7);
        expect(typeof trigger.after_days).toBe('number');
    });

    it('makes the serialized definition differ once the rule or the stage changes', () => {
        const canvas = seedFromDefinition(AGING_STAGE_DEFINITION);

        const baseline = JSON.stringify(canvas.toDefinition());

        canvas.trigger.value = { ...canvas.trigger.value, agingAfterDays: 30 };
        expect(JSON.stringify(canvas.toDefinition())).not.toBe(baseline);

        canvas.trigger.value = { ...canvas.trigger.value, agingAfterDays: 7 };
        expect(JSON.stringify(canvas.toDefinition())).toBe(baseline);

        canvas.trigger.value = {
            ...canvas.trigger.value,
            agingRuleId: OTHER_AGING_RULE_ID,
        };
        expect(JSON.stringify(canvas.toDefinition())).not.toBe(baseline);
    });
});
