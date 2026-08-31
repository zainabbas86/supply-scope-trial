<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorisation for a single document.
 *
 * List queries do NOT go through here — they use `Document::forOwner()`, so a
 * document belonging to someone else is never in the result set to begin with.
 * This covers the routes that load one record by id.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): Response
    {
        return $this->owns($user, $document)
            ? Response::allow()
            // 404, not 403. A 403 confirms the document exists and just is not
            // yours — with UUID ids that is a small leak, but it is still an
            // oracle: an attacker who guesses or obtains an id learns whether
            // it is real. "Not found" tells them nothing either way.
            : Response::denyAsNotFound();
    }

    /**
     * Re-dispatching an extraction spends money, so it is deliberately
     * narrower than viewing: only the owner, and only on a document that
     * actually failed. Retrying a completed document would pay for a second
     * extraction of something already extracted.
     */
    public function retry(User $user, Document $document): Response
    {
        if (! $this->owns($user, $document)) {
            return Response::denyAsNotFound();
        }

        return $document->hasFailed()
            ? Response::allow()
            : Response::deny('Only failed documents can be retried.');
    }

    public function delete(User $user, Document $document): Response
    {
        return $this->owns($user, $document)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Compared loosely on the key because morph ids come back as strings from
     * some drivers and integers from others; a strict === would silently deny
     * the real owner depending on the connection.
     */
    private function owns(User $user, Document $document): bool
    {
        return $document->owner_type === $user->getMorphClass()
            && (string) $document->owner_id === (string) $user->getKey();
    }
}
