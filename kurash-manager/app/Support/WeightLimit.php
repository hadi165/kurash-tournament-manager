<?php

namespace App\Support;

/**
 * Where one weight class sits on the scale, as something the code can order by.
 *
 * The running order is built lightest class first — see FightOrderScheduler —
 * and "lightest" is a question about kilograms. Neither of the two things that
 * were already to hand answers it. `id` is the order somebody happened to type
 * the classes in, and the label is a string: sorted as text, "-100" comes
 * before "-60" and "+100" before both, which puts the heaviest athletes of the
 * day on the first mat of the morning.
 *
 * ── Where the number comes from ──────────────────────────────────────────
 *
 * The label first, the stored bounds second. The label is the federation's own
 * name for the class, it is NOT NULL and unique within a division, and both
 * places that write a class — the categories screen and the demo seeder —
 * derive min_kg and max_kg *from* it. The bounds are nullable and a class may
 * carry neither, so reading them first would leave classes tied on nothing.
 *
 * ── Open classes ─────────────────────────────────────────────────────────
 *
 * "+100" and "-100" name the same number and are not the same class: the open
 * one is the heavier and must be numbered after it. So the limit is a figure
 * *and* a side, and sortKey() puts the open class second.
 */
final readonly class WeightLimit
{
    public function __construct(
        /** The kilogram figure the class is named for, or null if none was given. */
        public ?float $kg,
        /** Is this the open class above that figure, rather than the one up to it? */
        public bool $open = false,
    ) {}

    /**
     * Read a class label: "-60", "-66 kg", "+100 kg", "100+", "67,5", "60-66".
     *
     * The *last* number in the string is the limit. A bounded class states it
     * directly, and a class written as a band — "60-66" — is ordered by its
     * ceiling, which is the second of the two. A "+" anywhere makes it open;
     * that is the only thing the sign is read for, because a class with no
     * sign at all ("66") is still the class up to 66.
     *
     * Null when the label carries no number — "Open", "Absolute" — so the
     * caller can fall back to whatever the bounds say.
     */
    public static function fromLabel(?string $label): ?self
    {
        if ($label === null) {
            return null;
        }

        // Half the world writes 67,5 kg. Normalised before the digits are
        // read, or the match stops at the comma and the class becomes 67.
        $text = str_replace(',', '.', $label);

        preg_match_all('/\d+(?:\.\d+)?/', $text, $matches);

        if ($matches[0] === []) {
            return null;
        }

        return new self(
            kg: (float) $matches[0][count($matches[0]) - 1],
            open: str_contains($text, '+'),
        );
    }

    /**
     * Lightest first, and the open class after the bounded class it shares a
     * figure with.
     *
     * A class whose limit could not be established sorts last rather than
     * first: an unreadable label is a configuration mistake, and the cost of
     * guessing wrong is smaller at the end of the day than at the start of it.
     *
     * @return array{float, int}
     */
    public function sortKey(): array
    {
        return [$this->kg ?? INF, $this->open ? 1 : 0];
    }
}
