import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import UserPasswordCard from '@/components/users/UserPasswordCard.vue';

const { FormStub, routerMock } = vi.hoisted(() => ({
    FormStub: {
        name: 'FormStub',
        props: ['action', 'method', 'onSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    routerMock: { on: vi.fn(() => vi.fn()), visit: vi.fn(), post: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Form: FormStub,
    router: routerMock,
}));

type Wrapper = ReturnType<typeof mount>;

function mountCard(canReceiveResetLink = true): Wrapper {
    return mount(UserPasswordCard, {
        props: {
            userId: 'user-1',
            canReceiveResetLink,
            backHref: '/nubos/engine/users',
        },
    });
}

describe('users/UserPasswordCard', () => {
    it('keeps the save button disabled until a password is entered', async () => {
        const wrapper = mountCard();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeDefined();

        await wrapper.get('#user-password').setValue('a-new-password-1');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('sends the reset link for a user who may receive one', async () => {
        routerMock.post.mockClear();
        const wrapper = mountCard();

        await wrapper.get('[data-send-reset-link]').trigger('click');

        expect(routerMock.post).toHaveBeenCalledTimes(1);
    });

    it('disables the reset link for a user who may not receive one', () => {
        const wrapper = mountCard(false);

        expect(
            wrapper.get('[data-send-reset-link]').attributes('disabled'),
        ).toBeDefined();
    });
});
