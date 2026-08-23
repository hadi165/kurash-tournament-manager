<?php

namespace App\Support;

/**
 * The federation logo, but only when the PDF renderer can actually draw it.
 *
 * Dompdf rasterises PNG, JPEG and GIF through the GD extension, and throws
 * mid-render if GD is absent rather than skipping the image. That turns a
 * missing server extension into a 500 at the moment somebody needs a
 * certificate — so the check happens here instead, and the document simply
 * prints without its logo.
 *
 * SVG goes through dompdf's own parser and needs no extension.
 */
final class PrintLogo
{
    /** Absolute filesystem path, or null if there is nothing renderable. */
    public static function path(): ?string
    {
        // Print artwork first, then whatever the screens fly: the two are
        // usually the same file, and the setting exists for the case where
        // paper wants its own.
        foreach ([config('branding.logo_print'), config('branding.logo')] as $relative) {
            if (! is_string($relative) || $relative === '') {
                continue;
            }

            $absolute = public_path($relative);

            if (is_file($absolute) && self::isRenderable($absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    private static function isRenderable(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)
            ? extension_loaded('gd')
            : true;
    }
}
