import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { Attempt, DocumentDetail } from '@/types/documents';
import { appUrl } from '@/lib/url';

export default function Show({ document }: { document: DocumentDetail & { attempts_log?: Attempt[] } }) {
    const extraction = document.extraction;

    return (
        <AppLayout title={document.filename}>
            <Head title={document.filename} />

            <Link href={appUrl('/')} className="text-sm text-neutral-500 hover:text-neutral-900">
                ← All documents
            </Link>

            <div className="mt-4 flex flex-wrap items-center gap-3">
                <StatusBadge status={document.status} label={document.statusLabel} />
                <span className="text-xs text-neutral-500">
                    {document.mimeType}
                    {document.pageCount && ` · ${document.pageCount} pages`}
                    {` · ${(document.sizeBytes / 1024).toFixed(0)} KB`}
                </span>
            </div>

            {document.status === 'failed' && (
                <div role="alert" className="mt-6 rounded-md border border-red-200 bg-red-50 p-4">
                    <p className="text-sm font-medium text-red-900">Extraction failed</p>
                    <p className="mt-1 text-sm text-red-800">{document.failureReason}</p>
                    <button
                        type="button"
                        onClick={() => router.post(appUrl(`/documents/${document.id}/retry`))}
                        className="mt-3 rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-800 transition hover:bg-red-50"
                    >
                        Retry extraction
                    </button>
                </div>
            )}

            {(document.status === 'queued' || document.status === 'processing') && (
                <div className="mt-6 rounded-md border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                    This document is still being read. Extraction takes around 18 seconds.
                </div>
            )}

            {extraction && (
                <div className="mt-6 space-y-6">
                    {/*
                     * The banner that justifies the whole schema.
                     *
                     * When the allergen statement was left incomplete, the app must
                     * say so LOUDLY and explain that the allergens below were read
                     * out of the ingredient text — not declared by the manufacturer.
                     * Showing a quiet list here would imply a declaration that does
                     * not exist, on a food-safety document.
                     */}
                    {extraction.allergens?.statement_status === 'not_completed' && (
                        <div role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-4">
                            <p className="text-sm font-semibold text-amber-900">
                                Allergen statement was not completed on this document
                            </p>
                            <p className="mt-1 text-sm text-amber-800">
                                The allergens below were derived from the ingredient
                                declaration. They are <strong>not</strong> a declared allergen
                                statement and should be confirmed with the supplier.
                            </p>
                        </div>
                    )}

                    <Section title="Product">
                        <Field label="Product name" value={extraction.productName} page={extraction.productNamePage} />
                        <Field label="Brand" value={extraction.brand} page={extraction.brandPage} />
                        <Field label="Product type" value={extraction.productType.replace('_', '-')} />
                    </Section>

                    <Section title="Net weight">
                        {extraction.netWeight?.value != null ? (
                            <>
                                <Field
                                    label="Weight"
                                    value={`${extraction.netWeight.value} ${extraction.netWeight.unit ?? ''}`}
                                    page={extraction.netWeight.source_page}
                                />
                                {/* The basis is what makes the number mean anything:
                                    the same page may state a portion, a pack and a
                                    carton weight. */}
                                <Field label="Basis" value={extraction.netWeight.basis.replace('_', ' ')} />
                                <Field label="As printed" value={extraction.netWeight.raw_text} />
                            </>
                        ) : (
                            <NotFound label="Weight" />
                        )}
                    </Section>

                    <Section title="Allergens">
                        <Field
                            label="Statement"
                            value={extraction.allergens?.statement_status.replace(/_/g, ' ') ?? null}
                            page={extraction.allergens?.source_page ?? null}
                        />
                        <ListField label="Declared" items={extraction.allergens?.declared ?? []} />
                        <ListField
                            label="Derived from ingredients"
                            items={extraction.allergens?.derived_from_ingredients ?? []}
                            tone="amber"
                        />
                    </Section>

                    <Section title="Ingredients">
                        {extraction.ingredients?.raw_text ? (
                            <>
                                <Field
                                    label="As printed"
                                    value={extraction.ingredients.raw_text}
                                    page={extraction.ingredients.source_page}
                                />
                                <ListField label="Parsed" items={extraction.ingredients.items} />
                            </>
                        ) : (
                            <NotFound label="Ingredients" />
                        )}
                    </Section>

                    {extraction.warnings.length > 0 && (
                        <Section title="Model warnings">
                            <ul className="space-y-2">
                                {extraction.warnings.map((warning) => (
                                    <li key={warning} className="text-sm text-neutral-700">
                                        {warning}
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}

                    <Collapsible title="Raw provider response">
                        <pre className="max-h-96 overflow-auto rounded bg-neutral-900 p-4 text-xs text-neutral-100">
                            {JSON.stringify(extraction.raw, null, 2)}
                        </pre>
                    </Collapsible>
                </div>
            )}

            {document.attempts_log && document.attempts_log.length > 0 && (
                <Collapsible title={`Extraction attempts (${document.attempts_log.length})`}>
                    <table className="min-w-full text-sm">
                        <tbody className="divide-y divide-neutral-100">
                            {document.attempts_log.map((attempt) => (
                                <tr key={attempt.number}>
                                    <td className="py-2 pr-4 text-neutral-500">#{attempt.number}</td>
                                    <td className="py-2 pr-4 font-medium text-neutral-800">
                                        {attempt.outcome.replace(/_/g, ' ')}
                                    </td>
                                    <td className="py-2 pr-4 text-neutral-500">
                                        {attempt.latencyMs ? `${(attempt.latencyMs / 1000).toFixed(1)}s` : '—'}
                                    </td>
                                    <td className="py-2 pr-4 text-neutral-500">
                                        {attempt.tokens ? `${attempt.tokens} tokens` : '—'}
                                    </td>
                                    <td className="py-2 text-neutral-500">{attempt.errorMessage}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Collapsible>
            )}
        </AppLayout>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="rounded-lg border border-neutral-200 bg-white p-5">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                {title}
            </h2>
            <div className="space-y-3">{children}</div>
        </section>
    );
}

/**
 * A field, with the page it was read from.
 *
 * A null is rendered as an explicit "not found on the document" rather than a
 * blank cell. A blank is ambiguous — it could mean nothing was there, or that
 * something broke — and on a food-safety document that ambiguity is unsafe.
 */
function Field({
    label,
    value,
    page,
}: {
    label: string;
    value: string | null;
    page?: number | null;
}) {
    return (
        <div className="grid grid-cols-3 gap-4">
            <dt className="text-sm text-neutral-500">{label}</dt>
            <dd className="col-span-2 text-sm text-neutral-900">
                {value ?? <span className="text-neutral-400">Not found on the document</span>}
                {page != null && (
                    <span className="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
                        page {page}
                    </span>
                )}
            </dd>
        </div>
    );
}

function ListField({
    label,
    items,
    tone = 'neutral',
}: {
    label: string;
    items: string[];
    tone?: 'neutral' | 'amber';
}) {
    return (
        <div className="grid grid-cols-3 gap-4">
            <dt className="text-sm text-neutral-500">{label}</dt>
            <dd className="col-span-2">
                {items.length === 0 ? (
                    <span className="text-sm text-neutral-400">None</span>
                ) : (
                    <ul className="flex flex-wrap gap-1.5">
                        {items.map((item) => (
                            <li
                                key={item}
                                className={`rounded px-2 py-0.5 text-xs ${
                                    tone === 'amber'
                                        ? 'bg-amber-100 text-amber-900'
                                        : 'bg-neutral-100 text-neutral-700'
                                }`}
                            >
                                {item}
                            </li>
                        ))}
                    </ul>
                )}
            </dd>
        </div>
    );
}

function NotFound({ label }: { label: string }) {
    return (
        <p className="text-sm text-neutral-400">
            {label} was not found on this document.
        </p>
    );
}

function Collapsible({ title, children }: { title: string; children: React.ReactNode }) {
    const [open, setOpen] = useState(false);

    return (
        <section className="mt-6 rounded-lg border border-neutral-200 bg-white">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="flex w-full items-center justify-between px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-neutral-500 hover:text-neutral-900"
            >
                {title}
                <span aria-hidden="true">{open ? '−' : '+'}</span>
            </button>
            {open && <div className="border-t border-neutral-100 px-5 py-4">{children}</div>}
        </section>
    );
}
