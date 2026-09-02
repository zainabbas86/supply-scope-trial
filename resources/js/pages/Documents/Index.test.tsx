import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DocumentSummary } from '@/types/documents';

/*
 * Inertia is mocked rather than booted.
 *
 * usePage/Link/router/useForm all need a live Inertia app behind them, which
 * would turn a component test into an integration test with none of the
 * benefit — the server side of that integration is already covered by
 * DocumentRoutesTest hitting the real routes. What is worth testing here is
 * the rendering logic: which state the list shows, and what a user can act on.
 */
const pageProps = {
    auth: { user: { id: 1, name: 'Admin', email: 'admin' } },
    flash: {},
    rejected: [] as Array<{ filename: string; code: string; reason: string }>,
    uploaded: [] as Array<{ id: string; filename: string; status: string; duplicate_of_existing: boolean }>,
};

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>,
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props: pageProps }),
    useForm: () => ({
        data: { files: [] },
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        progress: null,
        reset: vi.fn(),
        errors: {},
    }),
}));

const { default: Index } = await import('./Index');

function makeDocument(overrides: Partial<DocumentSummary> = {}): DocumentSummary {
    return {
        id: 'doc-1',
        filename: 'coldwater-bay-spec.pdf',
        status: 'completed',
        statusLabel: 'Completed',
        pageCount: 3,
        attempts: 1,
        failureCode: null,
        failureReason: null,
        productName: 'Coldwater Bay – Fish – Fillets – Battered',
        brand: 'Coldwater Bay',
        createdAt: null,
        finishedAt: null,
        ...overrides,
    };
}

const props = { maxFiles: 20, maxFileSizeMb: 20 };

beforeEach(() => {
    pageProps.rejected = [];
    pageProps.uploaded = [];
    post.mockClear();
});

describe('Documents index', () => {
    it('shows a purposeful empty state, not a blank table', () => {
        render(<Index documents={[]} {...props} />);

        // The brief names empty states explicitly. A first-run user should be
        // told what this does and what to give it.
        expect(screen.getByText('No documents yet')).toBeInTheDocument();
        expect(screen.getByText(/18 seconds/)).toBeInTheDocument();
        expect(screen.queryByRole('table')).toBeNull();
    });

    it('shows the upload limits the server actually enforces', () => {
        render(<Index documents={[]} {...props} />);

        expect(screen.getByText(/up to 20 MB each/)).toBeInTheDocument();
        expect(screen.getByText(/20 files at a time/)).toBeInTheDocument();
    });

    it('lists documents with their extracted product name', () => {
        render(<Index documents={[makeDocument()]} {...props} />);

        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(screen.getByText('coldwater-bay-spec.pdf')).toBeInTheDocument();
        // Non-ASCII must survive all the way to the screen.
        expect(screen.getByText(/Coldwater Bay – Fish/)).toBeInTheDocument();
        expect(screen.getByText('Completed')).toBeInTheDocument();
    });

    it('shows the failure reason inline on a failed row', () => {
        render(
            <Index
                documents={[
                    makeDocument({
                        status: 'failed',
                        statusLabel: 'Failed',
                        failureCode: 'timeout',
                        failureReason: 'The AI service did not respond in time.',
                        productName: null,
                    }),
                ]}
                {...props}
            />,
        );

        // A user staring at a failed row wants to know why HERE, not one click
        // away on a detail page.
        expect(screen.getByText('The AI service did not respond in time.')).toBeInTheDocument();
    });

    it('offers retry only on failed documents', () => {
        const { rerender } = render(
            <Index
                documents={[makeDocument({ status: 'failed', statusLabel: 'Failed' })]}
                {...props}
            />,
        );
        expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();

        // Retrying a completed document would pay for a second extraction of
        // something already extracted.
        rerender(<Index documents={[makeDocument()]} {...props} />);
        expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
    });

    it('reports rejected files individually with the server reason', () => {
        pageProps.rejected = [
            { filename: 'notes.txt', code: 'unsupported_extension', reason: 'Files of type .txt are not supported.' },
            { filename: 'evil.pdf', code: 'content_type_mismatch', reason: 'Its contents are application/x-dosexec.' },
        ];

        render(<Index documents={[makeDocument()]} {...props} />);

        // One bad file must not hide the others, and must not hide the files
        // that DID upload.
        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(screen.getByText('2 files were not accepted')).toBeInTheDocument();
        expect(screen.getByText('notes.txt')).toBeInTheDocument();
        expect(screen.getByText(/application\/x-dosexec/)).toBeInTheDocument();
        expect(screen.getByRole('table')).toBeInTheDocument();
    });

    it('explains a deduplicated upload instead of silently completing it', () => {
        // Without this the file just appears instantly done, which reads like a
        // bug rather than the cost saving it is.
        pageProps.uploaded = [
            { id: 'doc-1', filename: 'spec.pdf', status: 'completed', duplicate_of_existing: true },
        ];

        render(<Index documents={[makeDocument()]} {...props} />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'spec.pdf had already been extracted',
        );
    });

    it('says nothing when an upload was not a duplicate', () => {
        pageProps.uploaded = [
            { id: 'doc-1', filename: 'spec.pdf', status: 'queued', duplicate_of_existing: false },
        ];

        render(<Index documents={[makeDocument()]} {...props} />);

        expect(screen.queryByRole('status')).toBeNull();
    });

    it('does not show a rejection banner when nothing was rejected', () => {
        render(<Index documents={[makeDocument()]} {...props} />);

        expect(screen.queryByRole('alert')).toBeNull();
    });
});
