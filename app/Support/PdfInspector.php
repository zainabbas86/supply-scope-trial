<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\FileRejected;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Opens a PDF far enough to answer two questions before anything is stored:
 * can it be read at all, and how many pages does it have?
 *
 * Uses smalot/pdfparser — pure PHP, so the runtime image needs no imagick,
 * Ghostscript or Poppler. That keeps the image small and free of a native
 * binary dependency, which matters for deployability.
 *
 * Note this is a *gate*, not text extraction. The sample specification sheets
 * contain zero embedded text (they are page images), so parsing them yields no
 * words at all — that is exactly why the extraction step needs a vision model.
 * All this does is prove the file is a readable, unencrypted PDF of a sane
 * length, so we do not hand the worker something it will choke on.
 */
final class PdfInspector
{
    /**
     * MEASURED, not guessed: parsing a real 283 KB specification sheet with
     * default settings exhausted a 128 MB memory limit outright.
     *
     * These sheets are page-sized images, and by default the parser
     * decompresses every embedded image into memory — to count pages. That is
     * both a crash on ordinary input and a cheap denial-of-service vector: a
     * modest PDF could exhaust the web container's memory.
     *
     * setRetainImageContent(false) makes RawDataParser skip image XObject
     * content entirely, which is exactly right here — page images are of no
     * interest to a page count, and the vision model reads the file itself
     * rather than anything this parser extracts.
     */
    private function config(): Config
    {
        $config = new Config;
        $config->setRetainImageContent(false);

        // Belt and braces: cap any single decompression regardless.
        $config->setDecodeMemoryLimit(16 * 1024 * 1024);

        return $config;
    }

    /**
     * Page count AND the text layer, from a single parse.
     *
     * One parse, not two: parsing these files is the expensive part - a real
     * 283 KB sheet exhausted 128 MB with default settings - so reading the
     * text separately would double the cost of every upload.
     *
     * The text is usually empty. These sheets are page images, and all four
     * supplied samples contain zero characters between them. It is extracted
     * anyway because a user can upload any PDF they like, and one built with a
     * text layer is the cheap way to attempt a prompt injection.
     *
     * @return array{pages: int, text: string}
     *
     * @throws FileRejected when the PDF is encrypted, corrupt, or too long
     */
    public function inspect(string $path): array
    {
        try {
            $pdf = (new Parser([], $this->config()))->parseFile($path);
        } catch (Throwable $e) {
            // smalot throws a bare \Exception for both cases, distinguished
            // only by message. Encryption deserves its own reason because it is
            // actionable ("remove the password and re-upload") while "corrupt"
            // is not.
            $message = $e->getMessage();

            if (str_contains($message, 'Secured pdf') || str_contains($message, 'Possible secured file')) {
                throw FileRejected::encryptedPdf();
            }

            throw FileRejected::unreadablePdf();
        }

        $pages = count($pdf->getPages());

        if ($pages < 1) {
            throw FileRejected::unreadablePdf();
        }

        $max = (int) config('uploads.max_pdf_pages');

        if ($pages > $max) {
            throw FileRejected::tooManyPages($pages, $max);
        }

        // getText() returns text regardless of how it is RENDERED - white on
        // white, one point tall, positioned off the page. That is precisely
        // what makes this worth scanning: hiding the text from a human does
        // not hide it from here.
        return ['pages' => $pages, 'text' => $pdf->getText()];
    }
}
