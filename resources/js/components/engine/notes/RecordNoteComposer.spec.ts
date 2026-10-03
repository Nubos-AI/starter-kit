import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';
import RecordNoteComposer from '@/components/engine/notes/RecordNoteComposer.vue';
import type * as NotesModule from '@/composables/useRecordNotes';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const RECORD_ID = '01RECORD00000000000000000A';

const notes = vi.hoisted(() => ({
    error: undefined as Ref<string | null> | undefined,
    saving: undefined as Ref<boolean> | undefined,
    create: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), reload: vi.fn(), get: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 'u1' } } } }),
}));

vi.mock('@/composables/useRecordNotes', async (importOriginal) => {
    const actual = await importOriginal<typeof NotesModule>();
    const { ref } = await import('vue');

    notes.error = ref<string | null>(null);
    notes.saving = ref<boolean>(false);

    return {
        ...actual,
        useRecordNotes: () => ({
            error: notes.error,
            saving: notes.saving,
            create: notes.create,
        }),
    };
});

function storedNote(body: string): NotesModule.RecordNoteItem {
    return {
        id: '01NOTE0000000000000000000B',
        body,
        author: { id: 'u1', label: 'Ada' },
        createdAt: '2026-08-24T10:00:00.000000Z',
        updatedAt: '2026-08-24T10:00:00.000000Z',
    };
}

function errorRef(): Ref<string | null> {
    if (notes.error === undefined) {
        throw new Error('The useRecordNotes mock was never initialised.');
    }

    return notes.error;
}

function savingRef(): Ref<boolean> {
    if (notes.saving === undefined) {
        throw new Error('The useRecordNotes mock was never initialised.');
    }

    return notes.saving;
}

function mountComposer(readonly = false): VueWrapper {
    return mount(RecordNoteComposer, {
        props: { recordId: RECORD_ID, readonly },
    });
}

async function type(wrapper: VueWrapper, body: string): Promise<void> {
    const field = wrapper.get('[data-record-note-body]');
    await field.setValue(body);
}

beforeEach(() => {
    notes.create.mockReset();
    notes.create.mockResolvedValue(storedNote('Angerufen.'));
    errorRef().value = null;
    savingRef().value = false;
});

describe('RecordNoteComposer', () => {
    it('offers a body field and a save button', () => {
        const wrapper = mountComposer();

        expect(wrapper.find('[data-record-note-composer]').exists()).toBe(true);
        expect(wrapper.find('[data-record-note-body]').exists()).toBe(true);
        expect(wrapper.find('[data-record-note-submit]').exists()).toBe(true);
    });

    it('labels the submit Speichern, never a per-form wording', () => {
        expect(mountComposer().get('[data-record-note-submit]').text()).toBe(
            'Speichern',
        );
    });

    it('passes the typed body and the record to the create call', async () => {
        const wrapper = mountComposer();

        await type(wrapper, 'Angerufen.');
        await wrapper.get('[data-record-note-submit]').trigger('click');
        await flushPromises();

        expect(notes.create).toHaveBeenCalledWith(RECORD_ID, 'Angerufen.');
    });

    it('clears the field once the note is stored', async () => {
        const wrapper = mountComposer();

        await type(wrapper, 'Angerufen.');
        await wrapper.get('[data-record-note-submit]').trigger('click');
        await flushPromises();

        expect(
            (
                wrapper.get('[data-record-note-body]')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe('');
    });

    it('announces the stored note so the strand can pick it up', async () => {
        const wrapper = mountComposer();

        await type(wrapper, 'Angerufen.');
        await wrapper.get('[data-record-note-submit]').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('created')).toHaveLength(1);
        expect(wrapper.emitted('created')?.[0]?.[0]).toMatchObject({
            body: 'Angerufen.',
        });
    });

    it('keeps the field and stays silent when the note was rejected', async () => {
        notes.create.mockResolvedValue(null);
        const wrapper = mountComposer();

        await type(wrapper, 'Angerufen.');
        await wrapper.get('[data-record-note-submit]').trigger('click');
        await flushPromises();

        expect(
            (
                wrapper.get('[data-record-note-body]')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe('Angerufen.');
        expect(wrapper.emitted('created')).toBeUndefined();
    });

    it('shows the rejection reason on the field', async () => {
        notes.create.mockImplementation(async () => {
            errorRef().value = 'The body field is required.';

            return null;
        });
        const wrapper = mountComposer();

        await type(wrapper, 'x');
        await wrapper.get('[data-record-note-submit]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('The body field is required.');
    });

    it('keeps the save button out of reach while the body is empty', async () => {
        const wrapper = mountComposer();

        expect(
            wrapper.get('[data-record-note-submit]').attributes('disabled'),
        ).toBeDefined();

        await type(wrapper, 'Angerufen.');

        expect(
            wrapper.get('[data-record-note-submit]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('disables the save button while the note is in flight', async () => {
        const wrapper = mountComposer();

        await type(wrapper, 'Angerufen.');
        savingRef().value = true;
        await wrapper.vm.$nextTick();

        expect(
            wrapper.get('[data-record-note-submit]').attributes('disabled'),
        ).toBeDefined();
    });

    it('leaves no composer on a read-only record', () => {
        expect(
            mountComposer(true).find('[data-record-note-composer]').exists(),
        ).toBe(false);
    });
});
