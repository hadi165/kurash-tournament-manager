<?php

namespace App\Services;

use App\Models\WeightCategory;
use App\Support\TournamentFormat;

/**
 * Which tournament a weight class may be run as, and which one it will be.
 *
 * The single place the athlete count is turned into a decision. Everywhere
 * else — the draw screen, the generator, the scheduler, the displays, the
 * exports — asks this class or reads the answer it already stored, so the rule
 * can be corrected in one file rather than found in nine.
 *
 * The rule, from the IKA competition rules
 * (https://kurash-ika.org/2022/08/20/kurash-rules/):
 *
 *   1 athlete    nothing to fight. The class is settled by an administrative
 *                placement, which somebody signs for.
 *   2-5          round robin. This is the rule, and it is the default.
 *   6 or more    knockout.
 *
 * Knockout in a field of two to five is available, but it is a local decision
 * taken against the IKA rule rather than an alternative reading of it. This
 * class will hand it out; it will not call it compliant, and it will not let
 * it happen without a named administrator and a reason.
 *
 * Round robin above five is not offered at all. A field of sixteen is a
 * hundred and twenty contests, which is not a scheduling preference — it is a
 * different competition.
 */
class TournamentFormatPolicy
{
    /** The largest field the IKA rule runs as a round robin. */
    public const SMALL_FIELD_MAX = 5;

    /**
     * Every format this field may legitimately be run as, best first.
     *
     * The head of the list is the default, so a caller that wants "whatever
     * the rule says" takes the first element and needs to know nothing else.
     *
     * @return list<TournamentFormat>
     */
    public function availableFor(int $athletes): array
    {
        if ($athletes < 1) {
            return [];
        }

        if ($athletes === 1) {
            return [TournamentFormat::Placement];
        }

        if ($athletes <= self::SMALL_FIELD_MAX) {
            return [TournamentFormat::RoundRobin, TournamentFormat::Knockout];
        }

        return [TournamentFormat::Knockout];
    }

    /** What this field is run as unless somebody says otherwise. */
    public function defaultFor(int $athletes): ?TournamentFormat
    {
        return $this->availableFor($athletes)[0] ?? null;
    }

    /** May this field be run this way at all? */
    public function allows(int $athletes, TournamentFormat $format): bool
    {
        return in_array($format, $this->availableFor($athletes), true);
    }

    /**
     * Is choosing this format a departure from the IKA rule?
     *
     * True only for knockout in a small field. Everything else is either the
     * rule itself or not permitted at all, and a format this policy refuses is
     * not an override — it is invalid, and allows() is what says so.
     */
    public function isOverride(int $athletes, TournamentFormat $format): bool
    {
        return $this->allows($athletes, $format) && ! $format->followsIkaRule($athletes);
    }

    /**
     * The format a draw would be generated in right now.
     *
     * Reads the administrator's stored preference and falls back to the rule.
     * A preference the field no longer permits is ignored rather than obeyed:
     * a class set to knockout at three athletes that has since grown to eight
     * is a knockout anyway, and one set to round robin that has grown past
     * five must not generate a hundred contests because of a box ticked when
     * it was small.
     */
    public function resolveFor(WeightCategory $category, ?int $athletes = null): ?TournamentFormat
    {
        $athletes ??= $category->drawnAthletes()->count();

        $preference = TournamentFormat::tryFromValue($category->draw_format_preference);

        if ($preference !== null && $this->allows($athletes, $preference)) {
            return $preference;
        }

        return $this->defaultFor($athletes);
    }

    /**
     * The format "just draw it" would actually produce.
     *
     * resolveFor(), except that a preference which departs from the rule is
     * not followed on nobody's say-so: an override needs choosing and signing
     * afresh every time, so without a signature the rule's default stands. A
     * class overridden to knockout at three athletes and redrawn without a
     * word is drawn as the round robin the rule gives it — and the selector
     * on the draw screen starts there too, rather than quietly re-proposing
     * the departure as the default.
     */
    public function resolveCompliantFor(WeightCategory $category, ?int $athletes = null): ?TournamentFormat
    {
        $athletes ??= $category->drawnAthletes()->count();

        $resolved = $this->resolveFor($category, $athletes);

        if ($resolved !== null && $this->isOverride($athletes, $resolved)) {
            return $this->defaultFor($athletes);
        }

        return $resolved;
    }

    /**
     * What a class was drawn as, which is not the same question.
     *
     * resolveFor() answers "what would happen if I drew this now";
     * this answers "what is on the table somebody is already presenting".
     * Screens and exports must ask this one, or a late registration silently
     * changes the shape of a published draw.
     */
    public function generatedFormat(WeightCategory $category): ?TournamentFormat
    {
        return TournamentFormat::tryFromValue($category->draw_format);
    }
}
