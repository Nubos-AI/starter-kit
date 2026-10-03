import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import TimelineFileEntry from '@/components/engine/files/TimelineFileEntry.vue';
import type { TimelineEntry } from '@/types/timeline';
import { setUrlDefaults } from '@/wayfinder';

const mocks = vi.hoisted(() => ({ remove: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    useHttp: () => reactive({ processing: false, delete: mocks.remove }),
}));
const entry: TimelineEntry = {
    id: 'timeline-1',
    sourceKey: 'file',
    sourceId: 'file-1',
    actorId: null,
    actorType: null,
    actorLabel: 'Anna',
    channel: 'web',
    occurredAt: '2026-09-19T12:00:00Z',
    canOpenAutomation: false,
    payload: { fileName: 'Vertrag.pdf' },
    file: {
        id: 'file-1',
        fileName: 'Vertrag.pdf',
        mimeType: 'application/pdf',
        size: 2048,
        createdAt: null,
        uploadedBy: null,
        fieldKey: null,
        canDelete: true,
    },
};
const confirmStub = {
    props: ['open', 'pending'],
    emits: ['confirm', 'update:open'],
    template:
        '<div v-if="open" data-confirm><button :disabled="pending" @click="$emit(\'confirm\')">Löschen bestätigen</button><slot /></div>',
};
function row(value = entry) {
    return mount(TimelineFileEntry, {
        props: { entry: value, recordId: 'record-1' },
        global: { stubs: { ConfirmDialog: confirmStub } },
    });
}
beforeEach(() => {
    mocks.remove.mockReset().mockResolvedValue(null);
    setUrlDefaults({ activeTeam: 'test-team' });
});

describe('TimelineFileEntry', () => {
    it('offers preview and download directly on the timeline entry', () => {
        const wrapper = row();
        expect(wrapper.text()).toContain('Vertrag.pdf');
        expect(wrapper.get('a[title="Herunterladen"]').attributes('href')).toBe(
            '/test-team/records/record-1/files/file-1',
        );
        expect(wrapper.get('a[title="Vorschau"]').attributes('href')).toContain(
            'preview=1',
        );
    });
    it('requires confirmation then removes actions and refreshes the timeline', async () => {
        const wrapper = row();
        await wrapper.get('button[title="Löschen"]').trigger('click');
        expect(mocks.remove).not.toHaveBeenCalled();
        await wrapper.get('[data-confirm] button').trigger('click');
        await flushPromises();
        expect(mocks.remove).toHaveBeenCalledWith(
            '/test-team/records/record-1/files/file-1',
        );
        expect(wrapper.emitted('changed')).toHaveLength(1);
        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.text()).toContain('nicht mehr verfügbar');
    });
    it('keeps failed deletions retryable and leaves the file available', async () => {
        mocks.remove.mockRejectedValue(new Error('storage offline'));
        const wrapper = row();
        await wrapper.get('button[title="Löschen"]').trigger('click');
        await wrapper.get('[data-confirm] button').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain(
            'nicht gelöscht',
        );
        expect(wrapper.find('a[title="Herunterladen"]').exists()).toBe(true);
        expect(wrapper.emitted('changed')).toBeUndefined();
    });
    it('shows history without dead download links for deleted files', () => {
        const wrapper = row({ ...entry, file: null });
        expect(wrapper.text()).toContain('Vertrag.pdf');
        expect(wrapper.text()).toContain('nicht mehr verfügbar');
        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
    });
    it('hides deletion when field or record write access is missing', () => {
        const wrapper = row({
            ...entry,
            file: { ...entry.file!, canDelete: false },
        });
        expect(wrapper.find('button[title="Löschen"]').exists()).toBe(false);
        expect(wrapper.find('a[title="Herunterladen"]').exists()).toBe(true);
    });
});
