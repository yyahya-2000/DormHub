<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-42, «changed at first login», enforced where the decision belongs.
 *
 * An account still holding the password the office printed for it may do three
 * things and no more: replace that password, read itself, and sign out. The
 * client redirects to the form as a convenience (§3.3.2); this refuses the rest
 * of the API to the token, which is what makes the criterion true of a caller
 * that never loads the client at all.
 *
 * The list is a whitelist rather than a list of what is closed, so a route
 * added tomorrow is closed until somebody decides otherwise.
 */
final class RequirePasswordChange
{
    /**
     * Without the first the change could not be made; without the other two
     * the account could neither see that it owes one nor get out of the
     * session it is stuck in.
     */
    private const OPEN = [
        'auth.password.change',
        'auth.me',
        'auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->password_change_required === true
            && ! in_array($request->route()?->getName(), self::OPEN, true)) {
            abort(403, 'The password issued with this account has to be changed before anything else.');
        }

        return $next($request);
    }
}
