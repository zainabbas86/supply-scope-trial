import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DocumentDetail, Extraction } from '@/types/documents';

const pageProps = {
    auth: { user: { id: 1, name: 'Admin', email: 'admin' } },
    flash: {},
    rejected: [],
    uploaded: [],
};

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>,
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props: pageProps }),
}));

const { default: Show } = await import('./Show');

function makeExtraction(overrides: Partial<Extraction> = {}): Extraction {
    return {
        productName: 'Coldwater Bay – Fish – Fillets – Battered',
        productNamePage: 1,
        brand: 'Coldwater Bay',
        brandPage: 1,
        productType: 'food',
        ingredients: {
            raw_text: 'Fish (Hoki) (58%), Water, Wheat Flour, Milk Solids.',
            items: ['Fish (Hoki) (58%)', 'Water', 'Wheat Flour', 'Milk Solids'],
            source_page: 2,
        },
        allergens: {
            statement_status: 'not_completed',
            declared: [],
            derived_from_ingredients: ['Fish', 'Wheat', 'Milk'],
            source_page: 2,
        },
        netWeight: {
            value: 800,
            unit: 'g',
            basis: 'per_pack',
            raw_text: 'NET Weight / Pack 800 g; Pack size 800g x 4 bags / carton',
            source_page: 1,
        },
        warnings: ['Allergen statement is marked VITAL NOT COMPLETED.'],
        schemaVersion: 1,
        raw: { stub: true },
        ...overrides,
    };
}

function makeDocument(overrides: Partial<DocumentDetail> = {}): DocumentDetail {
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
        sizeBytes: 282953,
        mimeType: 'application/pdf',
        sha256: 'a'.repeat(64),
        extraction: makeExtraction(),
        attempts_log: [
            {
                number: 1,
                outcome: 'success',
                model: 'gpt-5.5',
                httpStatus: 200,
                latencyMs: 15834,
                tokens: 4224,
                errorMessage: null,
                at: null,
            },
        ],
        ...overrides,
    };
}

beforeEach(() => post.mockClear());

describe('Document detail', () => {
    // -------------------------------------------------------------------------
    // The safety-critical one
    // -------------------------------------------------------------------------

    it('warns prominently when the allergen statement was never completed', () => {
        render(<Show document={makeDocument()} />);

        const banner = screen.getByText(/Allergen statement was not completed/);
        expect(banner).toBeInTheDocument();

        // The distinction the whole schema exists for: these allergens were
        // READ FROM the ingredients, not declared by the manufacturer. Showing
        // them as a quiet list would imply a declaration that does not exist,
        // on a food-safety document.
        expect(screen.getByText(/derived from the ingredient/i)).toBeInTheDocument();
        expect(screen.getByText(/confirmed with the supplier/i)).toBeInTheDocument();
    });

    it('does not warn when the statement was properly declared', () => {
        const document = makeDocument({
            extraction: makeExtraction({
                allergens: {
                    statement_status: 'declared',
                    declared: ['Fish', 'Wheat', 'Milk'],
                    derived_from_ingredients: [],
                    source_page: 2,
                },
            }),
        });

        render(<Show document={document} />);

        expect(screen.queryByText(/was not completed/)).toBeNull();
    });

    it('keeps declared and derived allergens visually separate', () => {
        render(<Show document={makeDocument()} />);

        expect(screen.getByText('Declared')).toBeInTheDocument();
        expect(screen.getByText('Derived from ingredients')).toBeInTheDocument();
        // Nothing was declared, and "None" says so explicitly rather than
        // leaving an empty space that could mean anything.
        expect(screen.getByText('None')).toBeInTheDocument();
        expect(screen.getByText('Fish')).toBeInTheDocument();
    });

    // -------------------------------------------------------------------------
    // Nulls and citations
    // -------------------------------------------------------------------------

    it('says a value was not found rather than leaving a blank', () => {
        const document = makeDocument({
            extraction: makeExtraction({ brand: null, brandPage: null }),
        });

        render(<Show document={document} />);

        // A blank cell is ambiguous — nothing there, or something broke? On a
        // food-safety document that ambiguity is unsafe.
        expect(screen.getByText('Not found on the document')).toBeInTheDocument();
    });

    it('cites the page every value was read from', () => {
        render(<Show document={makeDocument()} />);

        // Ingredients came from page 2, name and weight from page 1 — the
        // multi-page reading is the point, so the citation has to be visible.
        expect(screen.getAllByText('page 1').length).toBeGreaterThan(0);
        expect(screen.getAllByText('page 2').length).toBeGreaterThan(0);
    });

    it('keeps the basis that makes a net weight meaningful', () => {
        render(<Show document={makeDocument()} />);

        expect(screen.getByText('800 g')).toBeInTheDocument();
        // One page states a portion, a pack and a carton weight. "800g" alone
        // throws away which of the three it is.
        expect(screen.getByText('per pack')).toBeInTheDocument();
        expect(screen.getByText(/800g x 4 bags \/ carton/)).toBeInTheDocument();
    });

    it('reports a missing weight instead of rendering nothing', () => {
        const document = makeDocument({
            extraction: makeExtraction({
                netWeight: {
                    value: null,
                    unit: null,
                    basis: 'unknown',
                    raw_text: null,
                    source_page: null,
                },
            }),
        });

        render(<Show document={document} />);

        expect(screen.getByText(/Weight was not found on this document/)).toBeInTheDocument();
    });

    // -------------------------------------------------------------------------
    // States
    // -------------------------------------------------------------------------

    it('shows the failure reason and a retry for a failed document', async () => {
        const document = makeDocument({
            status: 'failed',
            statusLabel: 'Failed',
            failureCode: 'timeout',
            failureReason: 'The AI service could not process this document after 3 attempts.',
            extraction: null,
        });

        render(<Show document={document} />);

        expect(screen.getByText(/after 3 attempts/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /Retry extraction/ }));
        expect(post).toHaveBeenCalledWith('/documents/doc-1/retry');
    });

    it('tells the user work is still in progress', () => {
        const document = makeDocument({
            status: 'processing',
            statusLabel: 'Processing',
            extraction: null,
        });

        render(<Show document={document} />);

        expect(screen.getByText(/still being read/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Retry/ })).toBeNull();
    });

    // -------------------------------------------------------------------------
    // Progressive disclosure
    // -------------------------------------------------------------------------

    it('hides the raw provider response until asked for', async () => {
        render(<Show document={makeDocument()} />);

        const toggle = screen.getByRole('button', { name: /Raw provider response/ });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        await userEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });

    it('exposes the attempt history for debugging a failure', async () => {
        render(<Show document={makeDocument()} />);

        // This is the observability surface: how long it took and what it cost,
        // answerable without reading container logs.
        await userEvent.click(screen.getByRole('button', { name: /Extraction attempts \(1\)/ }));
        expect(screen.getByText('15.8s')).toBeInTheDocument();
        expect(screen.getByText('4224 tokens')).toBeInTheDocument();
    });
});
