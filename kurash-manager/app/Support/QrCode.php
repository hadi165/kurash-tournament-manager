<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Throwable;

/**
 * A QR code as an inline SVG, drawn from the encoder's module matrix.
 *
 * Bacon ships renderers, but the ones that produce an image need Imagick or GD
 * configured, and Dompdf handles a table of filled cells more predictably than
 * a rasterised image scaled into a 20 mm box. Reading the matrix directly keeps
 * the card dependency-light and the print sharp at any size.
 */
final class QrCode
{
    /**
     * @return string|null SVG markup, or null if the payload could not be encoded
     */
    public static function svg(string $text, int $size = 120, string $colour = '#14150f'): ?string
    {
        try {
            $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        } catch (Throwable) {
            // A card without a QR code is still a usable card; a card that
            // throws on the way to the printer is not.
            return null;
        }

        $width = $matrix->getWidth();
        $height = $matrix->getHeight();

        if ($width === 0 || $height === 0) {
            return null;
        }

        $rects = '';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    // +0.02 on the size closes the hairline seams Dompdf leaves
                    // between adjacent rects at small scales.
                    $rects .= sprintf('<rect x="%d" y="%d" width="1.02" height="1.02"/>', $x, $y);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
            .'shape-rendering="crispEdges" fill="%s"><rect width="%d" height="%d" fill="#ffffff"/>%s</svg>',
            $size, $size, $width, $height, $colour, $width, $height, $rects
        );
    }

    /** The same SVG as a data URI, for an <img> tag. */
    public static function dataUri(string $text, int $size = 120): ?string
    {
        $svg = self::svg($text, $size);

        return $svg === null ? null : 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
