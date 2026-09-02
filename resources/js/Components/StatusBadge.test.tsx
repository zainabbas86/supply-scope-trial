import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatusBadge from './StatusBadge';
import type { DocumentStatus } from '@/types/documents';

describe('StatusBadge', () => {
    it.each([
        ['queued', 'Queued'],
        ['processing', 'Processing'],
        ['completed', 'Completed'],
        ['failed', 'Failed'],
    ] as [DocumentStatus, string][])('renders %s as readable text', (status, label) => {
        render(<StatusBadge status={status} label={label} />);

        // Status is never conveyed by colour alone. Roughly one in twelve men
        // has a colour vision deficiency, and red/green is the common axis —
        // exactly the pairing "failed" and "completed" would otherwise use.
        expect(screen.getByText(label)).toBeInTheDocument();
    });

    it('announces in-flight statuses to screen readers', () => {
        // A polled row changes from processing to completed with no page
        // navigation, so nothing would otherwise tell a non-sighted user.
        const { container } = render(<StatusBadge status="processing" label="Processing" />);

        expect(container.querySelector('[aria-live="polite"]')).not.toBeNull();
    });

    it('does not announce terminal statuses', () => {
        // A finished row is not going to change again; announcing it would be
        // noise every time the list re-renders.
        const { container } = render(<StatusBadge status="completed" label="Completed" />);

        expect(container.querySelector('[aria-live="polite"]')).toBeNull();
    });

    it('hides the decorative pulse from assistive technology', () => {
        const { container } = render(<StatusBadge status="processing" label="Processing" />);

        const dot = container.querySelector('.animate-pulse');
        expect(dot).not.toBeNull();
        // The motion is a third channel for sighted users; read aloud it is
        // meaningless, so it must not reach the accessibility tree.
        expect(dot).toHaveAttribute('aria-hidden', 'true');
    });

    it('gives failed and completed visually distinct treatments', () => {
        const { container: failed } = render(<StatusBadge status="failed" label="Failed" />);
        const failedClass = failed.firstElementChild?.className ?? '';

        cleanupRender();

        const { container: done } = render(<StatusBadge status="completed" label="Completed" />);
        const doneClass = done.firstElementChild?.className ?? '';

        expect(failedClass).not.toBe(doneClass);
    });
});

/** Vitest's cleanup runs between tests, not within one. */
function cleanupRender() {
    document.body.innerHTML = '';
}
