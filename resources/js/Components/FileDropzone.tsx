import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp';

/**
 * Multi-file upload.
 *
 * Client-side checks here are a COURTESY, not a control: extension and size are
 * both trivially forged, and the server sniffs the actual bytes regardless.
 * Their only job is to save someone a round trip for an obvious mistake. Every
 * rule enforced here is enforced again server-side, and the server is the one
 * that decides.
 */
export default function FileDropzone({
    maxFiles,
    maxFileSizeMb,
}: {
    maxFiles: number;
    maxFileSizeMb: number;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [localErrors, setLocalErrors] = useState<string[]>([]);

    const { setData, post, processing, progress, reset } = useForm<{ files: File[] }>({
        files: [],
    });

    const submit = (files: FileList | null) => {
        if (!files || files.length === 0) return;

        const chosen = Array.from(files);
        const problems: string[] = [];

        if (chosen.length > maxFiles) {
            problems.push(`You can upload at most ${maxFiles} files at once.`);
        }

        for (const file of chosen) {
            if (file.size > maxFileSizeMb * 1024 * 1024) {
                problems.push(`${file.name} is larger than ${maxFileSizeMb} MB.`);
            }
        }

        setLocalErrors(problems);
        if (problems.length > 0) return;

        setData('files', chosen);
        post('/documents', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                if (input.current) input.current.value = '';
            },
        });
    };

    return (
        <div>
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    submit(e.dataTransfer.files);
                }}
                onClick={() => input.current?.click()}
                onKeyDown={(e) => {
                    // Keyboard parity: a div is not a button, so Enter and
                    // Space have to be wired up by hand.
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        input.current?.click();
                    }
                }}
                role="button"
                tabIndex={0}
                aria-label="Choose files to upload"
                className={`cursor-pointer rounded-lg border-2 border-dashed p-8 text-center transition ${
                    dragging
                        ? 'border-teal-500 bg-teal-50'
                        : 'border-neutral-300 bg-white hover:border-neutral-400'
                } ${processing ? 'pointer-events-none opacity-60' : ''}`}
            >
                <input
                    ref={input}
                    type="file"
                    multiple
                    accept={ACCEPT}
                    className="hidden"
                    onChange={(e) => submit(e.target.files)}
                />

                <p className="text-sm font-medium text-neutral-800">
                    {processing ? 'Uploading…' : 'Drop label files here, or click to choose'}
                </p>
                <p className="mt-1 text-xs text-neutral-500">
                    PDF, JPEG, PNG or WebP · up to {maxFileSizeMb} MB each · {maxFiles} files at a time
                </p>
            </div>

            {progress && (
                <div className="mt-3">
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-neutral-200">
                        <div
                            className="h-full rounded-full bg-teal-600 transition-all"
                            style={{ width: `${progress.percentage ?? 0}%` }}
                        />
                    </div>
                    <p className="mt-1 text-xs text-neutral-500">
                        {progress.percentage ?? 0}% uploaded
                    </p>
                </div>
            )}

            {localErrors.length > 0 && (
                <ul className="mt-3 space-y-1" role="alert">
                    {localErrors.map((error) => (
                        <li key={error} className="text-sm text-red-600">
                            {error}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
