<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Identity\Credentials;
use App\Models\User;

/**
 * The extension point FR-08 requires: who verifies a credential is a choice
 * made in configuration, not in the service that signs a user in.
 *
 * Constraint C-04 keeps a real integration with the university identity
 * provider outside this iteration. What is built instead is the seam itself —
 * one implementation that checks the password stored in `users`, and a second
 * that stands in for an external provider, so that the claim «pluggable» is
 * demonstrated rather than asserted (§4.7.3).
 *
 * An implementation resolves a credential to a local user account. It does not
 * decide whether that account may sign in, does not count attempts and does
 * not issue a token: those are the authentication service's business.
 */
interface IdentityProvider
{
    /**
     * A stable identifier of the provider, recorded in the audit log so that
     * the origin of a session is visible afterwards.
     */
    public function name(): string;

    /**
     * The local account the credential belongs to, or NULL when the
     * credential is not valid. Implementations must not distinguish
     * «no such login» from «wrong secret» in their return value.
     */
    public function authenticate(Credentials $credentials): ?User;
}
