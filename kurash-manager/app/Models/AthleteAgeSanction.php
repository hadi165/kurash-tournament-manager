<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the Chief Referee's sanction log.
 *
 * A record of a decision, not a state. Rows are appended and never updated:
 * withdrawing a sanction writes a second row saying so, and whether one is in
 * force is the newest row's action — see App\Services\AgeSanctions.
 *
 * Deliberately no factory. A sanction only ever comes from AgeSanctions, which
 * is what guarantees the frozen columns describe the decision that was
 * actually taken; manufacturing one in isolation would produce an approval
 * nobody gave.
 *
 * @property string $action
 * @property CarbonImmutable|null $created_at
 */
class AthleteAgeSanction extends Model
{
    /** A youth admitted to an adults' competition under Section 25(2). */
    public const ACTION_GRANTED = 'granted';

    /** That admission withdrawn. */
    public const ACTION_REVOKED = 'revoked';

    /**
     * created_at is set by the database default and nothing ever updates the
     * row, so Eloquent is told not to manage a pair of timestamps that would
     * only ever be half true.
     */
    public $timestamps = false;

    protected $fillable = [
        'athlete_id', 'age_category_id', 'championship_id',
        'action', 'reason', 'acted_by',
        'policy_version', 'competition_year', 'birth_year', 'competition_age', 'age_group',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** Is this row a sanction rather than a withdrawal? */
    public function grants(): bool
    {
        return $this->action === self::ACTION_GRANTED;
    }

    /** @return BelongsTo<Athlete, $this> */
    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    /** @return BelongsTo<AgeCategory, $this> */
    public function ageCategory(): BelongsTo
    {
        return $this->belongsTo(AgeCategory::class);
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /**
     * The official who signed. Null once their account has been deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
