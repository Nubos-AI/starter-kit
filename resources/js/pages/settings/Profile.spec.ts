import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';
import Profile from '@/pages/settings/Profile.vue';

const { pageState, formState } = vi.hoisted(() => ({
    formState: {
        errors: {} as Record<string, string>,
        onSuccess: null as (() => void) | null,
    },
    pageState: {
        url: '/settings/profile',
        props: {
            auth: {
                user: {
                    salutation: null,
                    first_name: 'Ada',
                    last_name: 'Lovelace',
                    email: 'ada@example.com',
                    email_verified_at: null,
                },
                can: {},
                authority: null,
            },
            mustVerifyEmail: true,
            status: null as string | null,
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Form: {
        props: { onSuccess: { type: Function, default: null } },
        setup: (props: { onSuccess: (() => void) | null }) => {
            formState.onSuccess = props.onSuccess;

            return { formState };
        },
        template:
            '<form><slot :errors="formState.errors" :processing="false" /></form>',
    },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => pageState,
}));

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

function mountPage(pendingEmail: string | null = null) {
    return mount(Profile, {
        props: { salutations: [], pendingEmail },
        global: {
            stubs: {
                DeleteUser: true,
                SalutationSelect: true,
                TextLink: { template: '<button><slot /></button>' },
            },
        },
    });
}

afterEach(() => {
    pageState.props.status = null;
    formState.errors = {};
    formState.onSuccess = null;
    vi.mocked(toast.success).mockReset();
});

describe('settings/Profile', () => {
    it('confirms a resent verification link as a toast instead of inline text', () => {
        pageState.props.status = 'verification-link-sent';

        const wrapper = mountPage();

        expect(toast.success).toHaveBeenCalledWith(
            'Ein neuer Bestätigungslink wurde an Ihre E-Mail-Adresse gesendet.',
        );
        expect(
            wrapper.get('[data-test="verification-hint"]').text(),
        ).not.toContain('Ein neuer Bestätigungslink');
    });

    it('stays quiet while no verification link was sent', () => {
        mountPage();

        expect(toast.success).not.toHaveBeenCalled();
    });

    it('asks for no password while the email address stays the same', async () => {
        const wrapper = mountPage();

        await wrapper.get('#email').setValue('  ADA@example.com ');

        expect(
            wrapper.find('[data-test="current-password-field"]').exists(),
        ).toBe(false);
    });

    it('asks for the current password once the email address changes', async () => {
        const wrapper = mountPage();

        await wrapper.get('#email').setValue('ada.lovelace@example.com');

        const field = wrapper.get('[data-test="current-password-field"]');

        expect(field.text()).toContain('Aktuelles Passwort');
        expect(field.text()).toContain(
            'Bestätigen Sie die neue E-Mail-Adresse mit Ihrem aktuellen Passwort.',
        );
        expect(field.get('input').attributes('name')).toBe('current_password');
        expect(field.get('input').attributes('type')).toBe('password');
    });

    it('shows the refusal of a wrong current password beside the field', async () => {
        formState.errors = {
            current_password: 'Das eingegebene Passwort ist nicht korrekt.',
        };

        const wrapper = mountPage();

        await wrapper.get('#email').setValue('ada.lovelace@example.com');

        expect(
            wrapper.get('[data-test="current-password-field"]').text(),
        ).toContain('Das eingegebene Passwort ist nicht korrekt.');
    });

    it('names the requested address and keeps the current one while the change waits for confirmation', () => {
        const wrapper = mountPage('ada.lovelace@example.com');

        expect(wrapper.get('[data-test="pending-email-hint"]').text()).toBe(
            'Wir haben einen Bestätigungslink an „ada.lovelace@example.com“ gesendet. Bis Sie ihn bestätigen, bleibt Ihre bisherige Adresse gültig.',
        );
        expect((wrapper.get('#email').element as HTMLInputElement).value).toBe(
            'ada@example.com',
        );
    });

    it('shows no pending hint while no change waits for confirmation', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-test="pending-email-hint"]').exists()).toBe(
            false,
        );
    });

    it('returns the field to the current address once a requested change is saved', async () => {
        const wrapper = mountPage();

        await wrapper.get('#email').setValue('ada.lovelace@example.com');
        formState.onSuccess?.();
        await wrapper.vm.$nextTick();

        expect((wrapper.get('#email').element as HTMLInputElement).value).toBe(
            'ada@example.com',
        );
        expect(
            wrapper.find('[data-test="current-password-field"]').exists(),
        ).toBe(false);
    });
});
