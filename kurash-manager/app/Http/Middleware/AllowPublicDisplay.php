<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the venue display screens.
 *
 * Publishing draws, names and results to anyone with the URL is normal for a
 * championship — but it is a decision, not a default. Unless DISPLAY_PUBLIC is
 * switched on, these screens require a signed-in user like everything else.
 */
class AllowPublicDisplay
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('display.public') === true || Auth::check()) {
            return $next($request);
        }

        return redirect()->guest(route('login'));
    }
}
