<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds genuinely valid PDFs for tests.
 *
 * Why this exists rather than a committed fixture file: the sample spec sheets
 * live in `supply_scope_docs/`, which is gitignored (the brief's .docx carries a
 * live API key), so a fresh checkout or a CI runner does not have them. Tests
 * that depended on those files would fail everywhere except this machine.
 *
 * Why not a hand-written PDF string: an *almost* valid PDF is worse than no
 * fixture. A malformed one gets rejected as `unreadable_pdf`, so a test asserting
 * "a valid PDF is accepted" passes for entirely the wrong reason. The xref table
 * below is generated with real byte offsets, which is exactly the part that is
 * tedious to get right by hand — and the part smalot/pdfparser actually reads.
 */
final class PdfBuilder
{
    /** A valid PDF with the requested number of pages. */
    public static function withPages(int $pages): string
    {
        $objects = [];

        // 1: catalog, 2: page tree, 3..N+2: the pages themselves.
        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $kids[] = ($i + 3).' 0 R';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';

        for ($i = 0; $i < $pages; $i++) {
            $objects[$i + 3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>';
        }

        return self::assemble($objects, encrypted: false);
    }

    /**
     * A PDF that declares /Encrypt in its trailer.
     *
     * smalot/pdfparser refuses these outright, which is the behaviour the upload
     * validator relies on to reject password-protected documents.
     */
    public static function encrypted(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
            4 => '<< /Filter /Standard /V 1 /R 2 /O <00> /U <00> /P -1 >>',
        ];

        return self::assemble($objects, encrypted: true);
    }

    /** @param array<int, string> $objects */
    private static function assemble(array $objects, bool $encrypted): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            // Byte offset of this object from the start of the file — this is
            // what the xref table indexes, and what makes the file parseable.
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $startxref = strlen($pdf);
        $size = count($objects) + 1;

        $pdf .= "xref\n0 {$size}\n";
        // The free-list head. Every xref entry is exactly 20 bytes including
        // its trailing newline; a parser reads them by offset, so a byte out
        // means the whole table is misread.
        $pdf .= "0000000000 65535 f \n";

        for ($n = 1; $n < $size; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }

        $trailer = "<< /Size {$size} /Root 1 0 R";
        if ($encrypted) {
            $trailer .= ' /Encrypt 4 0 R';
        }
        $trailer .= ' >>';

        return $pdf."trailer\n{$trailer}\nstartxref\n{$startxref}\n%%EOF";
    }

    /** A real 1x1 PNG — the smallest thing finfo will call image/png. */
    public static function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /** A Windows executable header — the renamed-.exe attack. */
    public static function executable(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 256);
    }
}
