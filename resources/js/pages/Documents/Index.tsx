import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FileDropzone from '@/Components/FileDropzone';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { DocumentSummary, RejectedFile } from '@/types/documents';
import { appUrl } from '@/lib/url';

const POLL_MS = 2500;

interface Props {
    documents: DocumentSummary[];
    maxFiles: number;
    maxFileSizeMb: number;
}

export default function Index({ documents, maxFiles, maxFileSizeMb }: Props) {
    const [rows, setRows] = useState(documents);
    const { flash } = usePage().props;
    const rejected = (usePage().props.rejected ?? []) as RejectedFile[];
    const uploaded = usePage().props.uploaded ?? [];
    const duplicates = uploaded.filter((f) => f.duplicate_of_existing);

    // Inertia keeps the component mounted across visits, so the local copy has
    // to follow the server's when a new page of props arrives.
    useEffect(() => setRows(documents), [documents]);

    const inFlight = rows.some(
        (d) => d.status === 'queued' || d.status === 'processing',
    );

    /*
     * Poll only while something is actually moving, and stop the moment it is
     * not. Extraction takes ~18s, so a handful of polls covers a typical job.
     *
     * WebSockets are the production answer, and were cut deliberately: Reverb
     * means a third container plus sticky sessions, which is a lot of
     * infrastructure for a list that is idle almost all of the time.
     */
    useEffect(() => {
        if (!inFlight) return;

        const timer = setInterval(async () => {
            try {
                const response = await fetch(appUrl('/documents/status'), {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) return; // a 429 or a blip: just try again next tick
                const data = await response.json();
                setRows(data.documents);
            } catch {
                // Offline or a dropped request. Polling continues; there is
                // nothing useful to say to the user about one missed tick.
            }
        }, POLL_MS);

        return () => clearInterval(timer);
    }, [inFlight]);

    return (
        <AppLayout title="Documents">
            <Head title="Documents" />

            <FileDropzone maxFiles={maxFiles} maxFileSizeMb={maxFileSizeMb} />

            {/* A deduplicated upload would otherwise appear instantly complete
                with no explanation, which reads like a bug. Saying so also
                surfaces the cost saving as a feature rather than hiding it. */}
            {duplicates.length > 0 && (
                <div
                    role="status"
                    className="mt-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"
                >
                    {duplicates.length === 1
                        ? `${duplicates[0].filename} had already been extracted, so the existing result was reused.`
                        : `${duplicates.length} files had already been extracted, so their existing results were reused.`}
                </div>
            )}

            {/* Rejected files are reported per file, with the server's reason.
                A batch of twenty with one bad file still uploads nineteen. */}
            {rejected.length > 0 && (
                <div
                    role="alert"
                    className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4"
                >
                    <p className="text-sm font-medium text-amber-900">
                        {rejected.length} file{rejected.length > 1 ? 's were' : ' was'} not accepted
                    </p>
                    <ul className="mt-2 space-y-1">
                        {rejected.map((file) => (
                            <li key={file.filename} className="text-sm text-amber-800">
                                <span className="font-medium">{file.filename}</span> — {file.reason}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="mt-8">
                {rows.length === 0 ? <EmptyState /> : <DocumentTable rows={rows} />}
            </div>
        </AppLayout>
    );
}

/**
 * A purposeful first run, not a blank table.
 *
 * The brief names empty states explicitly. A user who has never uploaded
 * anything should be told what this does and what to give it.
 */
function EmptyState() {
    return (
        <div className="rounded-lg border border-dashed border-neutral-300 bg-white p-12 text-center">
            <h2 className="text-sm font-semibold text-neutral-900">No documents yet</h2>
            <p className="mx-auto mt-2 max-w-md text-sm text-neutral-500">
                Upload a product label or specification sheet and it will be read by a
                vision model. Extraction takes around 18 seconds per document, and this
                list updates on its own — no need to refresh.
            </p>
        </div>
    );
}

function DocumentTable({ rows }: { rows: DocumentSummary[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border border-neutral-200 bg-white">
            <table className="min-w-full divide-y divide-neutral-200 text-sm">
                <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" className="px-4 py-3 font-medium">File</th>
                        <th scope="col" className="px-4 py-3 font-medium">Product</th>
                        <th scope="col" className="px-4 py-3 font-medium">Status</th>
                        <th scope="col" className="px-4 py-3 font-medium sr-only">Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-neutral-100">
                    {rows.map((row) => (
                        <tr key={row.id} className="align-top">
                            <td className="px-4 py-3">
                                <Link
                                    href={appUrl(`/documents/${row.id}`)}
                                    className="font-medium text-neutral-900 hover:text-teal-700 hover:underline"
                                >
                                    {row.filename}
                                </Link>
                                <p className="mt-0.5 text-xs text-neutral-500">
                                    {row.pageCount ? `${row.pageCount} pages` : 'Image'}
                                    {row.attempts > 1 && ` · ${row.attempts} attempts`}
                                </p>
                            </td>

                            <td className="px-4 py-3 text-neutral-700">
                                {row.productName ?? (
                                    <span className="text-neutral-400">—</span>
                                )}
                                {row.brand && (
                                    <p className="mt-0.5 text-xs text-neutral-500">{row.brand}</p>
                                )}
                            </td>

                            <td className="px-4 py-3">
                                <StatusBadge status={row.status} label={row.statusLabel} />

                                {/* The failure reason is shown inline rather than
                                    hidden behind the detail page: a user staring
                                    at a failed row wants to know why, here. */}
                                {row.failureReason && (
                                    <p className="mt-1.5 max-w-xs text-xs text-red-700">
                                        {row.failureReason}
                                    </p>
                                )}
                            </td>

                            <td className="px-4 py-3 text-right">
                                {row.status === 'failed' && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                appUrl(`/documents/${row.id}/retry`),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="rounded-md border border-neutral-300 px-2.5 py-1 text-xs font-medium text-neutral-700 transition hover:bg-neutral-50"
                                    >
                                        Retry
                                    </button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
