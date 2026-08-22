<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A closed account authorises nothing.
 *
 * Deactivation has to bite on the next request rather than at the next login,
 * or closing an account leaves whoever is already signed in working for the
 * rest of the day. The session is ended here so the account cannot simply keep
 * clicking.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('This account has been deactivated. Ask an administrator to reopen it.'),
            ]);
        }

        return $next($request);
    }
}
