<?php

namespace App\Exports;

use Illuminate\Support\Str;

/**
 * The document type and filing reference printed in the header band.
 *
 * Federation paperwork gets filed, argued over and cited months later, so
 * every sheet carries a reference. It is derived rather than stored: the same
 * report always produces the same reference, which is what a citation needs,
 * without a registry table this system does not have. If documents ever need
 * to be registered — reprints numbered separately, say — this is the seam to
 * replace.
 */
final class DocumentReference
{
    /** "Entries by weight category" from EntriesByWeightCategoryReport. */
    public static function tag(Report $report): string
    {
        $name = Str::of(class_basename($report))->beforeLast('Report')->headline();

        return $name->isEmpty() ? 'Competition document' : (string) $name;
    }

    /** IKA-ENT-2026-014 — association, document class, year, stable sequence. */
    public static function reference(Report $report): string
    {
        $code = Str::of(class_basename($report))->headline()->explode(' ')->first() ?? 'DOC';

        return sprintf(
            'IKA-%s-%s-%03d',
            Str::upper(Str::substr($code, 0, 3)),
            now()->format('Y'),
            // Stable per document rather than per render: a reference that
            // changed every time it was printed would be no reference at all.
            crc32($report->filename()) % 1000,
        );
    }
}
