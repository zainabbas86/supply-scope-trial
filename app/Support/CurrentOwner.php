<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The single seam that answers "who owns the data in this request?".
 *
 * Today the answer is the authenticated user — the user *is* the tenant.
 * Introducing organisations later changes this one class to return the resolved
 * tenant, and nothing else in the application has to change: `documents` is
 * already polymorphic (`owner_type` / `owner_id`), every list query already goes
 * through `Document::forOwner()`, and every test already builds ownership
 * through the factory.
 *
 * That is the whole point of routing every ownership question through one
 * place. It is worth pointing at when asked how this would become multi-tenant.
 */
final class CurrentOwner
{
    /**
     * The owner for the current request.
     *
     * Throws rather than returning null: an unscoped query is a data leak, so
     * a missing owner must be a loud failure and never a silent "all rows".
     * Routes that can reach this are behind `auth` middleware, so in practice
     * this only fires on a genuine wiring mistake.
     */
    public static function resolve(): Model
    {
        return self::tryResolve() ?? throw new RuntimeException(
            'No authenticated owner in this context. Queue jobs and console '
            .'commands have no session — resolve ownership from the record '
            .'itself (e.g. $document->owner) rather than calling this.'
        );
    }

    /** Null-safe variant, for code that legitimately runs unauthenticated. */
    public static function tryResolve(): ?Model
    {
        return Auth::user();
    }

    public static function check(): bool
    {
        return self::tryResolve() !== null;
    }
}
