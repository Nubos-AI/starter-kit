import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PasswordConfirmationFields from '@/components/PasswordConfirmationFields.vue';

type Wrapper = ReturnType<typeof mount>;

function mountFields(props: Record<string, unknown> = {}): Wrapper {
    return mount(PasswordConfirmationFields, {
        props: {
            passwordLabel: 'Passwort',
            confirmationLabel: 'Passwort bestätigen',
            passwordRules: 'minlength: 8;',
            ...props,
        },
    });
}

describe('PasswordConfirmationFields', () => {
    it('keeps the names, ids and autocomplete hints the password managers rely on', () => {
        const wrapper = mountFields();
        const password = wrapper.get('#password');
        const confirmation = wrapper.get('#password_confirmation');

        expect(password.attributes('name')).toBe('password');
        expect(confirmation.attributes('name')).toBe('password_confirmation');
        expect(password.attributes('autocomplete')).toBe('new-password');
        expect(confirmation.attributes('autocomplete')).toBe('new-password');
        expect(password.attributes('passwordrules')).toBe('minlength: 8;');
        expect(confirmation.attributes('passwordrules')).toBe('minlength: 8;');
    });

    it('labels both fields and uses the label as placeholder', () => {
        const wrapper = mountFields();

        expect(wrapper.get('label[for="password"]').text()).toBe('Passwort');
        expect(wrapper.get('label[for="password_confirmation"]').text()).toBe(
            'Passwort bestätigen',
        );
        expect(wrapper.get('#password').attributes('placeholder')).toBe(
            'Passwort',
        );
    });

    it('shows each error under its own field', () => {
        const wrapper = mountFields({
            passwordError: 'Zu kurz.',
            confirmationError: 'Stimmt nicht überein.',
        });

        expect(wrapper.text()).toContain('Zu kurz.');
        expect(wrapper.text()).toContain('Stimmt nicht überein.');
    });

    it('reports typed values through the password and confirmation models', async () => {
        const wrapper = mountFields({ password: '', confirmation: '' });

        await wrapper.get('#password').setValue('geheim123');
        await wrapper.get('#password_confirmation').setValue('geheim124');

        expect(wrapper.emitted('update:password')).toEqual([['geheim123']]);
        expect(wrapper.emitted('update:confirmation')).toEqual([['geheim124']]);
    });

    it('marks both fields required and continues the tab order on request', () => {
        const wrapper = mountFields({ required: true, firstTabindex: 6 });

        expect(wrapper.get('#password').attributes('required')).toBeDefined();
        expect(
            wrapper.get('#password_confirmation').attributes('required'),
        ).toBeDefined();
        expect(wrapper.get('#password').attributes('tabindex')).toBe('6');
        expect(
            wrapper.get('#password_confirmation').attributes('tabindex'),
        ).toBe('7');
    });

    it('leaves required, autofocus and tab order out by default', () => {
        const password = mountFields().get('#password');

        expect(password.attributes('required')).toBeUndefined();
        expect(password.attributes('autofocus')).toBeUndefined();
        expect(password.attributes('tabindex')).toBeUndefined();
    });
});
