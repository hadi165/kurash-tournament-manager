<?php
/**
 * bracket-helpers.php — shared helpers for bracket-title derivation.
 * Include this instead of redefining bracketTitleFromCount() locally.
 */
if (!function_exists('bracketTitleFromCount')) {
    function bracketTitleFromCount(int $count): string
    {
        if ($count <= 1) return '-';
        if ($count === 2) return 'Final';
        if ($count <= 4) return 'Semi Final';
        if ($count <= 8) return '1/4 Final';
        if ($count <= 16) return '1/8 Final';
        if ($count <= 32) return '1/16 Final';
        return '1/32 Final';
    }
}

/**
 * Standard bracket size (next power of 2) for a given athlete count.
 */
if (!function_exists('bracketSizeFromCount')) {
    function bracketSizeFromCount(int $count): int
    {
        foreach ([2, 4, 8, 16, 32, 64] as $size) {
            if ($count <= $size) return $size;
        }
        return 64;
    }
}
