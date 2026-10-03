import { describe, expect, it } from 'vitest';
import { buttonVariants } from '@/components/ui/button';

describe('buttonVariants', () => {
    it('keeps the success intent on the Atlassian bold green', () => {
        const classes = buttonVariants({ variant: 'success' });

        expect(classes).toContain('bg-success-bold');
        expect(classes).toContain('text-inverse');
        expect(classes).not.toContain('bg-success-solid');
    });

    it('gives the create action its own lighter green', () => {
        const classes = buttonVariants({ variant: 'create' });

        expect(classes).toContain('bg-success-solid');
        expect(classes).toContain('text-success-solid-foreground');
        expect(classes).toContain('active:bg-success-solid-pressed');
        expect(classes).not.toContain('bg-primary');
    });

    it('leaves danger and warning on their Atlassian bold surfaces', () => {
        expect(buttonVariants({ variant: 'destructive' })).toContain(
            'bg-danger-bold',
        );
        expect(buttonVariants({ variant: 'warning' })).toContain(
            'bg-warning-bold',
        );
    });
});
