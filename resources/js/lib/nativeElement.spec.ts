import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { buttonElement, textareaElement } from '@/lib/nativeElement';

describe('nativeElement', () => {
    it('reaches the native textarea behind the ui component', () => {
        const wrapper = mount(Textarea);

        expect(textareaElement(wrapper.vm)).toBe(
            wrapper.find('textarea').element,
        );
    });

    it('reaches the native button behind the ui component', () => {
        const wrapper = mount(Button);

        expect(buttonElement(wrapper.vm)).toBe(wrapper.find('button').element);
    });

    it('takes a plain element unchanged', () => {
        const element = document.createElement('button');

        expect(buttonElement(element)).toBe(element);
    });

    it('answers null for the wrong element behind the ref', () => {
        const wrapper = mount(Textarea);

        expect(buttonElement(wrapper.vm)).toBeNull();
    });

    it('answers null before the ref is filled', () => {
        expect(textareaElement(null)).toBeNull();
        expect(buttonElement(undefined)).toBeNull();
    });
});
