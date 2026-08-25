<?php

namespace App\Support;

use App\Services\TournamentFormatPolicy;

/**
 * How a weight class is run.
 *
 * One enum rather than an athlete-count condition repeated in a controller, a
 * component, a scheduler and four views. Every screen asks a stored value what
 * it is; only TournamentFormatPolicy decides what a class may become, and only
 * at the moment a draw is generated.
 *
 * The values are the strings on the row. They are written into
 * `weight_categories.draw_format` and read back for the life of the
 * competition, so they are part of the schema and are not renamed.
 */
enum TournamentFormat: string
{
    /**
     * Single elimination, seeded, with byes and forward links.
     *
     * The only thing this system generated before formats existed, which is
     * why every historical draw is stamped with it.
     */
    case Knockout = 'knockout';

    /**
     * Everybody meets everybody once.
     *
     * The IKA rule for a small field: with five athletes a bracket decides the
     * class on two contests and sends three home having fought once, and the
     * round robin is what the rule asks for instead.
     */
    case RoundRobin = 'round_robin';

    /**
     * One entrant, no contest.
     *
     * A format rather than an absence of one, so a class with a single athlete
     * can be drawn, published, displayed and exported like any other instead
     * of being a hole every screen has to test for.
     */
    case Placement = 'placement';

    /** What an official calls it out loud, and what the sheets print. */
    public function label(): string
    {
        return match ($this) {
            self::Knockout => __('Knockout'),
            self::RoundRobin => __('Round Robin'),
            self::Placement => __('Administrative placement'),
        };
    }

    /**
     * Does this format follow the IKA rule for the field it is being used on?
     *
     * Takes the count because compliance is not a property of the format: a
     * knockout of sixteen is exactly what the rule asks for, and a knockout of
     * three is the local override this system makes an administrator sign for.
     */
    public function followsIkaRule(int $athletes): bool
    {
        return match ($this) {
            self::Placement => $athletes === 1,
            self::RoundRobin => $athletes >= 2 && $athletes <= TournamentFormatPolicy::SMALL_FIELD_MAX,
            self::Knockout => $athletes > TournamentFormatPolicy::SMALL_FIELD_MAX,
        };
    }

    /** Does a draw in this format consist of contests at all? */
    public function hasContests(): bool
    {
        return $this !== self::Placement;
    }

    /** Do winners walk forward into another contest? */
    public function advancesWinners(): bool
    {
        return $this === self::Knockout;
    }

    /** The stored string, or null, turned back into a case. */
    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
