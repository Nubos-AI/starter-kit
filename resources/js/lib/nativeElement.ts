type ElementHost = Element | { $el?: unknown } | null | undefined;

function nativeElement<T>(
    target: ElementHost,
    constructor: abstract new (...args: never[]) => T,
): T | null {
    if (target instanceof constructor) {
        return target;
    }

    const element =
        target !== null && typeof target === 'object' && '$el' in target
            ? target.$el
            : null;

    return element instanceof constructor ? element : null;
}

export function textareaElement(
    target: ElementHost,
): HTMLTextAreaElement | null {
    return nativeElement(target, HTMLTextAreaElement);
}

export function buttonElement(target: ElementHost): HTMLButtonElement | null {
    return nativeElement(target, HTMLButtonElement);
}
