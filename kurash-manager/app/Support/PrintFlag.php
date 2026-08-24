<?php

namespace App\Support;

/**
 * The flag as paper wants it.
 *
 * The screens fly the SVGs; paper cannot. Dompdf draws an SVG without
 * establishing a viewport for it, so artwork that reaches outside its own
 * viewBox is drawn on the page anyway — Kazakhstan across a whole running
 * order, over the names underneath, which is what one came back looking like.
 * Nothing bounds it: `overflow: hidden`, a background image and a clip path
 * inside the file were each tried and each ignored.
 *
 * A raster image cannot do that, because it is drawn into the box it is given.
 * So the print set is PNG, cut from the same vectors by `flags:rasterise` and
 * committed alongside them, and every nation flies one again.
 *
 * Dompdf reads PNG through GD. Without that extension it throws mid-render
 * rather than skipping the image, which would turn a missing server extension
 * into a 500 at the moment somebody needs a start list — so the check is here,
 * and the sheet prints with codes and no flags instead.
 */
final class PrintFlag
{
    /** Absolute path to a flag safe to print, or null where there is none. */
    public static function path(?string $noc): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $iso = Noc::iso($noc);

        if ($iso === null) {
            return null;
        }

        $path = public_path("flags/print/{$iso}.png");

        return is_file($path) ? $path : null;
    }
}
