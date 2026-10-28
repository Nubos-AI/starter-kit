import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SegmentShareDialog from '@/components/engine/segment/SegmentShareDialog.vue';
import Combobox from '@/components/ui/combobox/Combobox.vue';
import type { SegmentSummary } from '@/composables/useSegments';
import type {
    GranteeOption,
    GranteeType,
    SegmentShare,
} from '@/composables/useSegmentShares';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const passthrough = { template: '<div><slot /></div>' };

const ButtonStub = {
    props: ['disabled'],
    emits: ['click'],
    template:
        '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
};

const CheckboxStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
        '<button type="button" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
};

const stubs = {
    Select: passthrough,
    SelectContent: passthrough,
    SelectGroup: passthrough,
    SelectItem: passthrough,
    SelectItemText: passthrough,
    SelectLabel: passthrough,
    SelectTrigger: passthrough,
    SelectValue: passthrough,
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
    DialogClose: passthrough,
    Label: passthrough,
    Badge: passthrough,
    Skeleton: passthrough,
    Button: ButtonStub,
    Checkbox: CheckboxStub,
};

const granteeOptions: Record<GranteeType, GranteeOption[]> = {
    team: [
        { id: 'team-1', label: 'Vertrieb' },
        { id: 'team-2', label: 'Support' },
    ],
    role: [{ id: 'role-1', label: 'Administrator' }],
    user: [
        { id: 'user-1', label: 'Alice' },
        { id: 'user-2', label: 'Bob' },
        { id: 'user-3', label: 'Carol' },
    ],
};

const SEGMENT_ID = 'seg-owned';

interface WireShare {
    id: string;
    grantee_type: string;
    grantee_id: string;
    can_edit: boolean;
}

const GRANTEE_TYPE_FQCN: Record<GranteeType, string> = {
    team: 'App\\Models\\Team',
    role: 'App\\Models\\Role',
    user: 'App\\Models\\User',
};

const RAW_MODEL_NAMESPACE = 'App\\Models';

function segment(overrides: Partial<SegmentSummary> = {}): SegmentSummary {
    return {
        id: SEGMENT_ID,
        name: 'Segment X',
        object_type_id: 'ot-company',
        is_system: false,
        is_default: false,
        is_owner: true,
        ...overrides,
    };
}

function share(overrides: Partial<SegmentShare> = {}): WireShare {
    const merged: SegmentShare = {
        id: 'share-1',
        grantee_type: 'team',
        grantee_id: 'team-1',
        can_edit: false,
        ...overrides,
    };

    return {
        id: merged.id,
        grantee_type: GRANTEE_TYPE_FQCN[merged.grantee_type],
        grantee_id: merged.grantee_id,
        can_edit: merged.can_edit,
    };
}

function fakeResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function fakeNoContent(): Response {
    return {
        ok: true,
        status: 204,
        json: async () => {
            throw new Error('204 has no body');
        },
    } as unknown as Response;
}

interface FetchOptions {
    grants?: WireShare[];
    postStatus?: number;
    getRejects?: boolean;
    created?: WireShare;
}

const createdShare: WireShare = share({
    id: 'share-new',
});

function makeFetch(options: FetchOptions = {}): ReturnType<typeof vi.fn> {
    const {
        grants = [],
        postStatus = 201,
        getRejects = false,
        created = createdShare,
    } = options;

    return vi.fn().mockImplementation((_url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';

        if (method === 'GET') {
            if (getRejects) {
                return Promise.reject(new Error('network down'));
            }

            return Promise.resolve(fakeResponse(200, { data: grants }));
        }

        if (method === 'POST') {
            return Promise.resolve(fakeResponse(postStatus, { data: created }));
        }

        if (method === 'DELETE') {
            return Promise.resolve(fakeNoContent());
        }

        return Promise.resolve(fakeResponse(200, {}));
    });
}

function callsWithMethod(
    fetchMock: ReturnType<typeof vi.fn>,
    method: string,
): Array<[string, RequestInit]> {
    return fetchMock.mock.calls.filter(
        ([, init]) =>
            ((init as RequestInit | undefined)?.method ?? 'GET') === method,
    ) as Array<[string, RequestInit]>;
}

async function mountDialog(
    fetchMock: ReturnType<typeof vi.fn>,
    segmentOverrides: Partial<SegmentSummary> = {},
) {
    vi.stubGlobal('fetch', fetchMock);

    const wrapper = mount(SegmentShareDialog, {
        props: {
            open: true,
            segment: segment(segmentOverrides),
            granteeOptions,
        },
        global: { stubs },
    });

    await flushPromises();

    return wrapper;
}

function granteeSelectText(wrapper: ReturnType<typeof mount>): string {
    const combobox = wrapper.findComponent(Combobox);
    expect(combobox.exists()).toBe(true);

    return (combobox.props('options') as { label: string }[])
        .map((option) => option.label)
        .join(' ');
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('SegmentShareDialog — grantee-type switch (SC-4)', () => {
    it('shows exactly the options for the selected grantee type as team→role→user', async () => {
        const wrapper = await mountDialog(makeFetch());

        expect(granteeSelectText(wrapper)).toContain('Vertrieb');
        expect(granteeSelectText(wrapper)).toContain('Support');
        expect(granteeSelectText(wrapper)).not.toContain('Administrator');
        expect(granteeSelectText(wrapper)).not.toContain('Alice');

        await wrapper
            .find('[data-grantee-type-option="role"]')
            .trigger('click');
        await flushPromises();

        expect(granteeSelectText(wrapper)).toContain('Administrator');
        expect(granteeSelectText(wrapper)).not.toContain('Vertrieb');
        expect(granteeSelectText(wrapper)).not.toContain('Alice');

        await wrapper
            .find('[data-grantee-type-option="user"]')
            .trigger('click');
        await flushPromises();

        expect(granteeSelectText(wrapper)).toContain('Alice');
        expect(granteeSelectText(wrapper)).toContain('Bob');
        expect(granteeSelectText(wrapper)).toContain('Carol');
        expect(granteeSelectText(wrapper)).not.toContain('Administrator');
        expect(granteeSelectText(wrapper)).not.toContain('Vertrieb');
    });
});

describe('SegmentShareDialog — can_edit toggle (view vs edit, least privilege)', () => {
    it('posts can_edit:false by default when the edit toggle is left off', async () => {
        const fetchMock = makeFetch();
        const wrapper = await mountDialog(fetchMock);

        await wrapper.find('[data-share-create]').trigger('click');
        await flushPromises();

        const posts = callsWithMethod(fetchMock, 'POST');
        expect(posts).toHaveLength(1);

        const body = JSON.parse(posts[0][1].body as string) as Record<
            string,
            unknown
        >;
        expect(body.can_edit).toBe(false);
    });

    it('posts can_edit:true after the edit toggle is switched on', async () => {
        const fetchMock = makeFetch();
        const wrapper = await mountDialog(fetchMock);

        await wrapper.find('[data-can-edit-toggle]').trigger('click');
        await flushPromises();

        await wrapper.find('[data-share-create]').trigger('click');
        await flushPromises();

        const posts = callsWithMethod(fetchMock, 'POST');
        expect(posts).toHaveLength(1);

        const body = JSON.parse(posts[0][1].body as string) as Record<
            string,
            unknown
        >;
        expect(body.can_edit).toBe(true);
    });
});

describe('SegmentShareDialog — create grant (correct POST)', () => {
    it('sends exactly one POST to the shares endpoint with the selected grantee', async () => {
        const fetchMock = makeFetch();
        const wrapper = await mountDialog(fetchMock);

        await wrapper
            .find('[data-grantee-type-option="user"]')
            .trigger('click');
        await flushPromises();

        await wrapper.find('[data-share-create]').trigger('click');
        await flushPromises();

        const posts = callsWithMethod(fetchMock, 'POST');
        expect(posts).toHaveLength(1);

        expect(posts[0][0]).toBe(`/nubos/engine/segments/${SEGMENT_ID}/shares`);

        const body = JSON.parse(posts[0][1].body as string) as Record<
            string,
            unknown
        >;
        expect(Object.keys(body).sort()).toEqual([
            'can_edit',
            'grantee_id',
            'grantee_type',
        ]);
        expect(body.grantee_type).toBe('user');
        expect(body.grantee_id).toBe('user-1');
        expect(body.can_edit).toBe(false);
    });

    it('keeps the request body grantee_type as the SHORT key, never the FQCN (no normalization bleed into the write path)', async () => {
        const fetchMock = makeFetch();
        const wrapper = await mountDialog(fetchMock);

        await wrapper.find('[data-share-create]').trigger('click');
        await flushPromises();

        await wrapper
            .find('[data-grantee-type-option="user"]')
            .trigger('click');
        await flushPromises();

        await wrapper.find('[data-share-create]').trigger('click');
        await flushPromises();

        const posts = callsWithMethod(fetchMock, 'POST');
        expect(posts).toHaveLength(2);

        const sentGranteeTypes = posts.map((post) => {
            const body = JSON.parse(post[1].body as string) as Record<
                string,
                unknown
            >;

            return body.grantee_type;
        });

        expect(sentGranteeTypes).toEqual(['team', 'user']);

        for (const value of sentGranteeTypes) {
            expect(value).not.toContain(RAW_MODEL_NAMESPACE);
        }
    });
});

describe('SegmentShareDialog — FQCN grantee_type normalization (real wire shape)', () => {
    it.each([
        ['team', 'team-1', 'Vertrieb'],
        ['role', 'role-1', 'Administrator'],
        ['user', 'user-1', 'Alice'],
    ] as ReadonlyArray<readonly [GranteeType, string, string]>)(
        'resolves the local label for a loaded %s grant delivered with an FQCN grantee_type',
        async (type, granteeId, label) => {
            const grants = [
                share({
                    id: 'share-1',
                    grantee_type: type,
                    grantee_id: granteeId,
                }),
            ];

            const wrapper = await mountDialog(makeFetch({ grants }));

            const entry = wrapper.find('[data-grant-entry]');
            expect(entry.exists()).toBe(true);

            const text = entry.text();
            expect(text).toContain(label);
            expect(text).not.toContain(granteeId);
            expect(text).not.toContain(RAW_MODEL_NAMESPACE);
        },
    );

    it('normalizes every FQCN grantee_type in a mixed loaded list', async () => {
        const grants = [
            share({
                id: 'share-team',
                grantee_type: 'team',
                grantee_id: 'team-1',
            }),
            share({
                id: 'share-role',
                grantee_type: 'role',
                grantee_id: 'role-1',
            }),
            share({
                id: 'share-user',
                grantee_type: 'user',
                grantee_id: 'user-1',
            }),
        ];

        const wrapper = await mountDialog(makeFetch({ grants }));

        const entries = wrapper.findAll('[data-grant-entry]');
        expect(entries).toHaveLength(3);

        const byId = (id: string) =>
            entries.find((entry) => entry.attributes('data-grant-id') === id)!;

        expect(byId('share-team').text()).toContain('Vertrieb');
        expect(byId('share-team').text()).not.toContain('team-1');
        expect(byId('share-role').text()).toContain('Administrator');
        expect(byId('share-role').text()).not.toContain('role-1');
        expect(byId('share-user').text()).toContain('Alice');
        expect(byId('share-user').text()).not.toContain('user-1');

        for (const entry of entries) {
            expect(entry.text()).not.toContain(RAW_MODEL_NAMESPACE);
        }
    });

    it('normalizes the FQCN grantee_type on the POST response so the appended row shows the resolved label', async () => {
        const created = share({
            id: 'share-created',
            grantee_type: 'user',
            grantee_id: 'user-1',
        });

        const fetchMock = makeFetch({ created });
        const wrapper = await mountDialog(fetchMock);

        await wrapper
            .find('[data-grantee-type-option="user"]')
            .trigger('click');
        await flushPromises();

        await wrapper.find('[data-share-create]').trigger('click');
        await flushPromises();

        const entry = wrapper
            .findAll('[data-grant-entry]')
            .find(
                (node) => node.attributes('data-grant-id') === 'share-created',
            );

        expect(entry).toBeDefined();

        const text = entry!.text();
        expect(text).toContain('Alice');
        expect(text).not.toContain('user-1');
        expect(text).not.toContain(RAW_MODEL_NAMESPACE);
    });
});

describe('SegmentShareDialog — grants list + revoke (SC-4)', () => {
    it('renders one entry per loaded grant with view vs edit visually distinguished', async () => {
        const grants = [
            share({ id: 'share-1', can_edit: false }),
            share({
                id: 'share-2',
                grantee_type: 'user',
                grantee_id: 'user-1',
                can_edit: true,
            }),
        ];

        const wrapper = await mountDialog(makeFetch({ grants }));

        const entries = wrapper.findAll('[data-grant-entry]');
        expect(entries).toHaveLength(2);

        const byId = (id: string) =>
            entries.find((entry) => entry.attributes('data-grant-id') === id)!;

        expect(byId('share-1').exists()).toBe(true);
        expect(byId('share-1').attributes('data-grant-can-edit')).toBe('false');
        expect(byId('share-1').text()).toContain('Vertrieb');

        expect(byId('share-2').attributes('data-grant-can-edit')).toBe('true');
        expect(byId('share-2').text()).toContain('Alice');
    });

    it('sends a DELETE to the specific grant endpoint when revoke is clicked', async () => {
        const grants = [
            share({ id: 'share-1' }),
            share({ id: 'share-2', grantee_id: 'team-2' }),
        ];

        const fetchMock = makeFetch({ grants });
        const wrapper = await mountDialog(fetchMock);

        const entry = wrapper
            .findAll('[data-grant-entry]')
            .find((node) => node.attributes('data-grant-id') === 'share-2')!;

        await entry.find('[data-grant-revoke]').trigger('click');
        await flushPromises();

        const deletes = callsWithMethod(fetchMock, 'DELETE');
        expect(deletes).toHaveLength(1);
        expect(deletes[0][0]).toBe(
            `/nubos/engine/segments/${SEGMENT_ID}/shares/share-2`,
        );
    });
});

describe('SegmentShareDialog — creator-only gate (defense in depth)', () => {
    it('does not render share controls when the viewer is not the segment owner', async () => {
        const wrapper = await mountDialog(makeFetch(), { is_owner: false });

        expect(wrapper.findAll('[data-grantee-type-option]')).toHaveLength(0);
        expect(wrapper.find('[data-grantee-select]').exists()).toBe(false);
        expect(wrapper.find('[data-share-create]').exists()).toBe(false);
        expect(wrapper.find('[data-can-edit-toggle]').exists()).toBe(false);
    });
});

describe('SegmentShareDialog — error surfacing (no silent catch)', () => {
    it('shows a visible error when creating a grant responds non-OK', async () => {
        const fetchMock = makeFetch({ postStatus: 500 });
        const wrapper = await mountDialog(fetchMock);

        await wrapper.find('[data-share-create]').trigger('click');
        await flushPromises();

        const error = wrapper.find('[data-share-error]');
        expect(error.exists()).toBe(true);
        expect(error.text().trim().length).toBeGreaterThan(0);
    });

    it('shows a visible error when loading grants rejects', async () => {
        const wrapper = await mountDialog(makeFetch({ getRejects: true }));

        const error = wrapper.find('[data-share-error]');
        expect(error.exists()).toBe(true);
        expect(error.text().trim().length).toBeGreaterThan(0);
    });
});

describe('SegmentShareDialog — local label resolution (no backend label)', () => {
    it('falls back to the grantee_id when no matching option exists', async () => {
        const grants = [share({ id: 'share-1', grantee_id: 'team-deleted' })];

        const wrapper = await mountDialog(makeFetch({ grants }));

        const entry = wrapper.find('[data-grant-entry]');
        expect(entry.exists()).toBe(true);
        expect(entry.text()).toContain('team-deleted');
    });
});

describe('SegmentShareDialog — empty grantee options (sane empty state)', () => {
    it('keeps the create action disabled and does not crash when the selected grantee type has no options', async () => {
        vi.stubGlobal('fetch', makeFetch());

        const wrapper = mount(SegmentShareDialog, {
            props: {
                open: true,
                segment: segment(),
                granteeOptions: {
                    team: [],
                    role: [{ id: 'role-1', label: 'Administrator' }],
                    user: [],
                },
            },
            global: { stubs },
        });

        await flushPromises();

        const createButton = wrapper.find('[data-share-create]');
        expect(createButton.exists()).toBe(true);
        expect(createButton.attributes('disabled')).toBeDefined();
        expect(granteeSelectText(wrapper).trim()).toBe('');
    });
});
