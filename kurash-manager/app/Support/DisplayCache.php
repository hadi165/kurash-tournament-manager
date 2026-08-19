<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for the public display screens.
 *
 * The problem this solves: a bracket on `wire:poll.3s` in front of 200
 * spectators is ~66 requests a second, each booting the framework, hydrating a
 * Livewire component and querying the database. That is what falls over at an
 * event — not the data, which is tiny.
 *
 * Rather than expiring on a timer and hoping, every screen for a championship
 * hangs off a single version number. Bumping it invalidates all of them at
 * once, so a result is visible on the next request and stale results are
 * impossible.
 */
class DisplayCache
{
    private const VERSION_PREFIX = 'display:version:';

    /** Current version for a championship. Starts at 1 rather than 0 so a missing key is obvious. */
    public static function version(int $championshipId): int
    {
        return (int) Cache::get(self::VERSION_PREFIX.$championshipId, 1);
    }

    /**
     * Invalidate every cached screen for this championship.
     *
     * Cache::increment is atomic, so two mats finishing at the same instant
     * cannot both read the same version and write it back.
     */
    public static function bump(int $championshipId): void
    {
        $key = self::VERSION_PREFIX.$championshipId;

        // increment() does nothing on a missing key in some stores, so seed it.
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }

        Cache::increment($key);
    }

    /**
     * Remember a rendered screen against the championship's current version.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $name, int $championshipId, Closure $callback): mixed
    {
        return Cache::remember(
            self::key($name, $championshipId),
            config('display.ttl', 300),
            $callback
        );
    }

    /** The full cache key, which is also what the ETag is derived from. */
    public static function key(string $name, int $championshipId): string
    {
        return "display:{$championshipId}:".self::version($championshipId).":{$name}";
    }
}
