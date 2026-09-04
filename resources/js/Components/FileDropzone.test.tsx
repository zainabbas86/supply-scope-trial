import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const setData = vi.fn();

let formState = {
    data: { files: [] as File[] },
    setData: (...args: unknown[]) => setData(...args),
    post: (...args: unknown[]) => post(...args),
    processing: false,
    progress: null as { percentage: number } | null,
    reset: vi.fn(),
    errors: {},
};

vi.mock('@inertiajs/react', () => ({
    useForm: () => formState,
}));

const { default: FileDropzone } = await import('./FileDropzone');

const props = { maxFiles: 3, maxFileSizeMb: 1 };

function file(name: string, sizeBytes: number): File {
    const f = new File(['x'], name, { type: 'application/pdf' });
    // File size is read-only, so it has to be defined rather than assigned.
    Object.defineProperty(f, 'size', { value: sizeBytes });

    return f;
}

beforeEach(() => {
    post.mockClear();
    setData.mockClear();
    formState = { ...formState, processing: false, progress: null };
});

describe('FileDropzone', () => {
    it('states the limits the server actually enforces', () => {
        render(<FileDropzone {...props} />);

        expect(screen.getByText(/up to 1 MB each/)).toBeInTheDocument();
        expect(screen.getByText(/3 files at a time/)).toBeInTheDocument();
    });

    it('is reachable and operable by keyboard', async () => {
        // A div is not a button: role, tabIndex and Enter/Space all have to be
        // wired by hand or the control is mouse-only.
        render(<FileDropzone {...props} />);

        const zone = screen.getByRole('button', { name: /Choose files to upload/ });
        expect(zone).toHaveAttribute('tabIndex', '0');

        await userEvent.tab();
        expect(zone).toHaveFocus();
    });

    it('accepts only the file types the server allows', () => {
        const { container } = render(<FileDropzone {...props} />);

        const input = container.querySelector('input[type="file"]');
        expect(input).toHaveAttribute('accept', '.pdf,.jpg,.jpeg,.png,.webp');
        expect(input).toHaveAttribute('multiple');
    });

    it('rejects an oversized file before spending a round trip', async () => {
        const { container } = render(<FileDropzone {...props} />);
        const input = container.querySelector('input[type="file"]') as HTMLInputElement;

        await userEvent.upload(input, file('huge.pdf', 5 * 1024 * 1024));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'huge.pdf is larger than 1 MB',
        );
        // The check is a COURTESY, not a control — the server sniffs the bytes
        // regardless. Its only job is saving a round trip on an obvious mistake.
        expect(post).not.toHaveBeenCalled();
    });

    it('rejects more files than the server will take', async () => {
        const { container } = render(<FileDropzone {...props} />);
        const input = container.querySelector('input[type="file"]') as HTMLInputElement;

        await userEvent.upload(input, [
            file('a.pdf', 1000),
            file('b.pdf', 1000),
            file('c.pdf', 1000),
            file('d.pdf', 1000),
        ]);

        expect(screen.getByRole('alert')).toHaveTextContent('at most 3 files at once');
        expect(post).not.toHaveBeenCalled();
    });

    it('uploads as multipart when the files are acceptable', async () => {
        const { container } = render(<FileDropzone {...props} />);
        const input = container.querySelector('input[type="file"]') as HTMLInputElement;

        await userEvent.upload(input, file('spec.pdf', 1000));

        expect(post).toHaveBeenCalledWith(
            '/documents',
            // forceFormData: an Inertia visit is JSON by default, and a File
            // cannot survive JSON serialisation.
            expect.objectContaining({ forceFormData: true, preserveScroll: true }),
        );
    });

    it('shows upload progress while a large file is in flight', () => {
        formState = { ...formState, progress: { percentage: 42 } };

        render(<FileDropzone {...props} />);

        expect(screen.getByText('42% uploaded')).toBeInTheDocument();
    });

    it('blocks further input while uploading', () => {
        formState = { ...formState, processing: true };

        render(<FileDropzone {...props} />);

        expect(screen.getByText('Uploading…')).toBeInTheDocument();
    });
});

describe('dropped files', () => {
    // The `accept` attribute filters the file PICKER only. Files arriving via
    // drag-and-drop never pass through it, so this is the only thing standing
    // between a dragged .exe and a full upload that the server then rejects.
    it('rejects an unsupported type dropped onto the zone', async () => {
        render(<FileDropzone {...props} />);

        const zone = screen.getByRole('button');
        const bad = file('payload.exe', 1000);

        fireEvent.drop(zone, { dataTransfer: { files: [bad] } });

        expect(await screen.findByText(/not a supported file type/i)).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });

    it('accepts a supported type dropped onto the zone', () => {
        render(<FileDropzone {...props} />);

        fireEvent.drop(screen.getByRole('button'), {
            dataTransfer: { files: [file('label.pdf', 1000)] },
        });

        expect(post).toHaveBeenCalled();
    });
});
