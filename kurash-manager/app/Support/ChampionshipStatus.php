<?php

namespace App\Support;

/**
 * How far along a championship is, as the dashboard reports it.
 *
 * Derived on every read from the dates and the bout rows, never stored. A
 * status column would be a second source of truth for something the bouts
 * already answer, and the failure mode is the worst one this system has: a
 * competition that says "Completed" while a mat is still fighting because
 * somebody forgot to change a dropdown.
 *
 * The values exist only to give the cases a stable name for tests and CSS
 * hooks; nothing writes them to the database.
 */
enum ChampionshipStatus: string
{
    /** Dated, but its first day has not arrived. */
    case Upcoming = 'upcoming';

    /** Under way by the calendar, with nothing decided yet. */
    case Setup = 'setup';

    /** A contest is on a mat this moment. */
    case Live = 'live';

    /** Results are coming in, but no mat is occupied right now. */
    case InProgress = 'in_progress';

    /** Every contest that could be decided has been. */
    case Completed = 'completed';

    /** What the badge reads. */
    public function label(): string
    {
        return match ($this) {
            self::Upcoming => __('Upcoming'),
            self::Setup => __('Setup'),
            self::Live => __('Live'),
            self::InProgress => __('In progress'),
            self::Completed => __('Completed'),
        };
    }

    /**
     * Which x-ui.tag variant carries it.
     *
     * Live and Completed are both "good" states and would both be brand, so
     * Live takes the dot instead — see dotted(). Colour alone never
     * distinguishes them, because a projector at the back of a hall and a
     * red-green colourblind official are the two viewers this system has.
     */
    public function tagVariant(): string
    {
        return match ($this) {
            self::Live => 'brand',
            self::Completed => 'brand',
            self::InProgress => 'info',
            self::Setup => 'amber',
            self::Upcoming => 'muted',
        };
    }

    /** Only a genuinely live competition gets the pulsing dot. */
    public function dotted(): bool
    {
        return $this === self::Live;
    }
}
