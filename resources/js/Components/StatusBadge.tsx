import type { DocumentStatus } from '@/types/documents';

/**
 * Status is never conveyed by colour alone.
 *
 * Roughly one in twelve men has some form of colour vision deficiency, and
 * red/green is the common axis — which is precisely the pairing "failed" and
 * "completed" would otherwise use. Every badge carries its label as text, and
 * the in-flight ones carry motion as a third channel.
 */
const styles: Record<DocumentStatus, string> = {
    queued: 'bg-neutral-100 text-neutral-700 ring-neutral-200',
    processing: 'bg-sky-50 text-sky-800 ring-sky-200',
    completed: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    failed: 'bg-red-50 text-red-800 ring-red-200',
};

export default function StatusBadge({
    status,
    label,
}: {
    status: DocumentStatus;
    label: string;
}) {
    const inFlight = status === 'queued' || status === 'processing';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${styles[status]}`}
            // Screen readers announce the change when a polled row moves from
            // processing to completed without a page navigation.
            aria-live={inFlight ? 'polite' : undefined}
        >
            {status === 'processing' && (
                <span
                    aria-hidden="true"
                    className="size-1.5 animate-pulse rounded-full bg-sky-600"
                />
            )}
            {label}
        </span>
    );
}
