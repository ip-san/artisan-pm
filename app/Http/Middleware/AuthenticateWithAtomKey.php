<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an Atom feed be read with `?key=<atom key>` instead of a login —
 * feed readers cannot log in. The key authenticates that one request only
 * (no session, no cookie) and only for an active user; without a valid key
 * the request must already be logged in, as before. Redmine's
 * accept_atom_auth.
 */
final class AuthenticateWithAtomKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->query('key');
        $viaKey = false;

        if (is_string($key) && $key !== '' && ! Auth::check()) {
            $user = User::query()->where('atom_key', $key)->first();

            if ($user !== null && $user->isActive()) {
                Auth::onceUsingId($user->getKey());
                $viaKey = true;
            }
        }

        if (! Auth::check()) {
            return redirect()->guest(route('login'));
        }

        try {
            return $next($request);
        } finally {
            // The key vouches for this request only; nothing of it may leak
            // into whatever the same process handles next.
            if ($viaKey) {
                Auth::forgetUser();
            }
        }
    }
}
