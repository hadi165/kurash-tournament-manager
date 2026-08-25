<?php

namespace App\Support;

use App\Models\WeightCategory;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many draws are ready to put in front of a hall.
 *
 * The sidebar badge, and only that. "Presentable" is narrower than "drawn":
 * publication is the deliberate act that says a draw may be shown, so a
 * generated but unpublished bracket is not counted — the operator screen would
 * refuse to present it and a badge promising one that is not there sends
 * somebody to an empty list mid-session.
 *
 * Archived championships are excluded for the same reason the draws screen
 * excludes them: the badge and the list it labels must agree, or the number is
 * worse than no number.
 */
final class PresentableDraws
{
    public static function count(): int
    {
        return WeightCategory::query()
            ->whereNotNull('draw_published_at')
            ->whereHas(
                'ageCategory.championship',
                fn (Builder $q) => $q->whereNull('archived_at')
            )
            ->count();
    }
}
