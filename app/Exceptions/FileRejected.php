<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * One file failed validation.
 *
 * Thrown per file, caught per file. A batch of twenty uploads where one is a
 * renamed executable must still accept the other nineteen — rejecting the whole
 * request would make the user re-pick every file to find the bad one.
 *
 * Carries a stable machine `code` alongside the human sentence, for the same
 * reason `documents` has both `failure_code` and `failure_reason`: the UI shows
 * one, tests and aggregation branch on the other.
 */
class FileRejected extends RuntimeException
{
    /**
     * Named `failureCode`, not `code`: \Exception already declares an int
     * $code, and PHP refuses to redeclare an inherited non-readonly property as
     * readonly. The name also matches the `documents.failure_code` column, so
     * the same identifier means the same thing at every layer.
     */
    public function __construct(
        public readonly string $failureCode,
        string $reason,
    ) {
        parent::__construct($reason);
    }

    public static function tooLarge(int $maxKb): self
    {
        $mb = round($maxKb / 1024, 1);

        return new self('file_too_large', "This file is larger than the {$mb} MB limit.");
    }

    public static function unsupportedExtension(string $extension, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            'unsupported_extension',
            $extension === ''
                ? "This file has no extension. Accepted types are: {$list}."
                : "Files of type .{$extension} are not supported. Accepted types are: {$list}."
        );
    }

    /**
     * The graded case: the extension and the actual bytes disagree, which is
     * what an executable renamed to .pdf looks like.
     */
    public static function contentMismatch(string $extension, string $sniffed): self
    {
        return new self(
            'content_type_mismatch',
            "This file is named .{$extension} but its contents are {$sniffed}. It was not accepted."
        );
    }

    public static function emptyFile(): self
    {
        return new self('empty_file', 'This file is empty.');
    }

    public static function unreadablePdf(): self
    {
        return new self('unreadable_pdf', 'This PDF could not be opened. It may be corrupt.');
    }

    public static function encryptedPdf(): self
    {
        return new self(
            'encrypted_pdf',
            'This PDF is password protected, so its contents cannot be read.'
        );
    }

    public static function tooManyPages(int $pages, int $max): self
    {
        return new self(
            'too_many_pages',
            "This PDF has {$pages} pages. The limit is {$max}, because longer documents cannot be processed within the time budget."
        );
    }

    public static function uploadFailed(): self
    {
        return new self('upload_failed', 'This file did not upload correctly. Please try again.');
    }
}
