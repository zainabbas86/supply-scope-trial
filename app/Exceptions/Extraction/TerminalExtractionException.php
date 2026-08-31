<?php

declare(strict_types=1);

namespace App\Exceptions\Extraction;

/**
 * Permanent. The job must fail immediately without burning retries.
 *
 * A 400 will be a 400 on all three attempts. Retrying it wastes the budget,
 * holds a worker, and — worse for the person waiting — delays a failure they
 * could already have been told about by up to two backoff periods.
 */
class TerminalExtractionException extends ExtractionException
{
    public static function badRequest(string $detail, ?array $raw = null): self
    {
        return new self(
            'invalid_request',
            'This document could not be processed by the AI service.',
            $detail,
            400,
            $raw,
        );
    }

    public static function unauthorized(int $status): self
    {
        return new self(
            'provider_auth_failed',
            'The AI service rejected our credentials. An administrator needs to look at this.',
            "OpenAI returned {$status} — check OPENAI_API_KEY",
            $status,
        );
    }

    public static function unsupportedContent(string $mime): self
    {
        return new self(
            'unsupported_content',
            "This file type ({$mime}) cannot be read by the AI service.",
            "Provider rejected content type {$mime}",
            400,
        );
    }

    /**
     * The model declined to answer. Not an error in the transport sense — the
     * call succeeded — so it must be recognised explicitly, or it looks like a
     * malformed response and gets pointlessly retried.
     */
    public static function refused(string $reason, ?array $raw = null): self
    {
        return new self(
            'model_refusal',
            'The AI service declined to process this document.',
            "Model refusal: {$reason}",
            200,
            $raw,
        );
    }

    /**
     * Structured output still failed server-side validation after the single
     * repair attempt. Terminal by design: a second repair round would be an
     * unbounded loop against a model that is not converging.
     */
    public static function invalidOutput(string $detail, ?array $raw = null): self
    {
        return new self(
            'invalid_output',
            'The AI service returned data we could not verify, so it was rejected rather than shown.',
            $detail,
            200,
            $raw,
        );
    }

    /** The file vanished from storage between upload and extraction. */
    public static function fileMissing(string $path): self
    {
        return new self(
            'file_missing',
            'The uploaded file could no longer be found in storage.',
            "Missing at {$path}",
            null,
        );
    }
}
