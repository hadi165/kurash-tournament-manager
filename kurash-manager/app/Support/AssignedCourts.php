<?php

namespace App\Support;

use App\Models\Court;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The mats one account may work, as a query rather than as a filter.
 *
 * Three screens ask this — the referee's landing page, the sidebar, and the
 * scoreboard selector — and if each carried its own idea of the answer, one of
 * them would eventually offer a mat the others refuse. Scoping in the query
 * also means a tampered id finds nothing rather than being caught afterwards
 * by a check somebody remembered to write.
 */
final class AssignedCourts
{
    /** @return Collection<int, Court> */
    public static function for(?User $user): Collection
    {
        if ($user === null) {
            return new Collection;
        }

        $courtIds = $user->refereeCourtIds();

        // A referee with nothing assigned works nothing. Distinct from null,
        // which is an account that is not limited to particular mats at all.
        if ($courtIds !== null && $courtIds === []) {
            return new Collection;
        }

        return Court::query()
            ->where('is_active', true)
            ->when($courtIds !== null, fn ($query) => $query->whereIn('id', $courtIds ?? []))
            ->whereHas('championship', function ($query) use ($user, $courtIds) {
                $query->whereNull('archived_at');

                // The championship scope still applies to the accounts it was
                // made for. A referee is scoped by their mats instead, and a
                // mat belongs to exactly one championship anyway.
                if ($courtIds === null && $user->scoreboard_championship_id !== null) {
                    $query->whereKey($user->scoreboard_championship_id);
                }
            })
            ->with('championship')
            ->orderBy('championship_id')
            ->orderBy('number')
            ->get();
    }
}
