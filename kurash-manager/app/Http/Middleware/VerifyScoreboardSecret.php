<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret authentication for scoreboard callbacks.
 *
 * Fails closed. The original webhook fell back to a literal 'CHANGE_ME' when
 * the environment variable was missing, which meant forgetting to set it left
 * the endpoint open to anyone who had read the source.
 */
class VerifyScoreboardSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('scoreboard.webhook_secret');

        // Anything that is not a non-empty string counts as unconfigured, so a
        // secret set to an array or a bare `true` in config fails closed too
        // rather than being stringified into something guessable.
        if (! is_string($expected) || $expected === '') {
            Log::error('SCOREBOARD_WEBHOOK_SECRET is not set — refusing all scoreboard callbacks.');

            return response()->json([
                'status' => 'error',
                'message' => 'Webhook is not configured.',
            ], 503);
        }

        $header = config('scoreboard.webhook_header');
        $header = is_string($header) && $header !== '' ? $header : 'X-Scoreboard-Token';

        // Symfony's HeaderBag returns ?string, unlike Request::header(), which
        // is documented as string|array|null and would need a cast.
        $provided = $request->headers->get($header) ?? '';

        if (! hash_equals($expected, $provided)) {
            Log::warning('Rejected a scoreboard callback with a bad token', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook token.',
            ], 401);
        }

        return $next($request);
    }
}
