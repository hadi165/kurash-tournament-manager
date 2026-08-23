<?php

namespace App\Support;

/**
 * A flag that can be trusted to stay inside its own box on paper.
 *
 * Dompdf draws SVG without establishing a viewport for it: nothing in the
 * artwork is bounded to the box the page asked for, and there is no way to
 * impose one. `overflow: hidden` is ignored, a background image needs GD, and
 * a clip path inside the file is ignored as well — all three were tried.
 *
 * Sixteen of the two hundred flags have an element that reaches outside their
 * own viewBox. Most overshoot by a few points; Kazakhstan, Grenada and
 * Honduras draw across the whole page, over the names underneath, which is
 * what a running order came back looking like.
 *
 * So those sixteen print without a flag, and their code prints alone. A gap in
 * a column is a small fault; a flag drawn over the results is a large one.
 *
 * ── Measuring this again ──────────────────────────────────────────────────
 *
 * Render each flag alone at 18×12 inside generous padding, then walk the
 * content stream applying the transform stack and take the extent of the
 * drawn points. A flag that behaves stays inside its box; these do not. The
 * list is data, not a guess, and it should be re-measured whenever the flag
 * artwork is replaced.
 *
 * ── Fixing it properly ────────────────────────────────────────────────────
 *
 * Raster artwork cannot spill: a PNG is drawn into the box it is given. That
 * needs the GD extension, which this system does not have — with it, and a
 * set of PNG flags, every nation could fly one and this list could go.
 */
final class PrintFlag
{
    /**
     * Flags whose artwork Dompdf draws outside the box it was given.
     *
     * ISO 3166-1 alpha-2, the same codes the files are named by.
     *
     * @var list<string>
     */
    public const UNBOUNDED = [
        'af', 'bi', 'bj', 'dm', 'et', 'fm', 'gd', 'hn',
        'ir', 'ki', 'kp', 'kz', 'ly', 'nr', 'pw', 'zm',
    ];

    /** Absolute path to a flag safe to print, or null where there is none. */
    public static function path(?string $noc): ?string
    {
        $iso = Noc::iso($noc);

        if ($iso === null || in_array($iso, self::UNBOUNDED, true)) {
            return null;
        }

        return Noc::flagPath($noc);
    }
}
