<?php

namespace App\Support;

/**
 * The competitions a championship can be run for.
 *
 * Stored as the single letters the weight class table has always used, kept in
 * one place because a championship now declares which of them it runs and every
 * screen has to agree on the vocabulary.
 */
final class Gender
{
    public const MEN = 'M';

    public const WOMEN = 'F';

    /** A class open to anyone, which some invitationals still run. */
    public const OPEN = 'X';

    /** @var list<string> */
    public const ALL = [self::MEN, self::WOMEN, self::OPEN];

    /** What a championship runs unless the organizer says otherwise. */
    public const DEFAULT = [self::MEN, self::WOMEN];

    /** "Men", "Women", "Open" — how a division is named on paper. */
    public static function label(?string $gender): string
    {
        return match ($gender) {
            self::MEN => 'Men',
            self::WOMEN => 'Women',
            default => 'Open',
        };
    }

    /**
     * Keeps only the letters this system knows, in a stable order, without
     * duplicates. Anything else — a stray value posted at the form, a legacy
     * row — is dropped rather than carried forward.
     *
     * @param  iterable<mixed>  $genders
     * @return list<string>
     */
    public static function sanitise(iterable $genders): array
    {
        $kept = [];

        foreach ($genders as $gender) {
            if (is_string($gender) && in_array($gender, self::ALL, true) && ! in_array($gender, $kept, true)) {
                $kept[] = $gender;
            }
        }

        return array_values(array_filter(self::ALL, fn (string $g) => in_array($g, $kept, true)));
    }
}
