<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One contest. Named Bout rather than Match because PHP 8 reserves `match`
 * as a language keyword — `class Match` will not parse. The underlying table
 * is `bouts` so the naming stays consistent end to end.
 *
 * The clock columns are declared here for the same reason Championship
 * declares its dates: static analysis reads the migration and cannot see that
 * casts() turns the timestamp into a Carbon instance.
 *
 * @property int|null $clock_seconds_left
 * @property bool $clock_running
 * @property Carbon|null $clock_updated_at
 * @property Carbon|null $jazzo_called_at
 * @property Carbon|null $jazzo_resumed_at
 * @property int|null $decided_seconds_remaining
 */
class Bout extends Model
{
    // Deliberately no HasFactory. A bout only ever comes from one of the draw
    // generators — BracketGenerator for a knockout, RoundRobinGenerator for a
    // small field — because that is what guarantees a contest belongs to a
    // draw: the bracket's forward links and seeding in one case, and every
    // pairing exactly once in the other. Manufacturing one in isolation would
    // produce a contest that is part of neither.
    //
    // Which of the two wrote a row is not stored here. It is the weight
    // class's draw_format, recorded when the draw was generated, so a contest
    // cannot disagree with the draw it belongs to.

    public const STATUS_PENDING = 'pending';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ON_COURT = 'on_court';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'play_code', 'championship_id', 'age_category_id', 'weight_category_id',
        'round', 'position_in_round', 'fight_number', 'court_id',
        'athlete_a_id', 'athlete_b_id', 'seed_a', 'seed_b',
        'next_bout_id', 'next_bout_slot',
        'score_a', 'score_b', 'win_type', 'winner_athlete_id',
        'status', 'is_bye', 'frozen_snapshot', 'scoreboard_synced_at',
        'clock_seconds_left', 'clock_running', 'clock_updated_at',
        'jazzo_called_at', 'jazzo_resumed_at', 'decided_seconds_remaining',
    ];

    protected function casts(): array
    {
        return [
            'is_bye' => 'boolean',
            'frozen_snapshot' => 'array',
            'scoreboard_synced_at' => 'datetime',
            'score_a' => 'decimal:1',
            'score_b' => 'decimal:1',
            'clock_running' => 'boolean',
            'clock_updated_at' => 'datetime',
            'jazzo_called_at' => 'datetime',
            'jazzo_resumed_at' => 'datetime',
        ];
    }

    /**
     * Seconds left on the contest clock, right now.
     *
     * Derived from the stored anchor rather than read off a ticking column, so
     * a scoreboard that has just been plugged in shows the right time
     * immediately instead of waiting for the next write.
     */
    public function secondsRemaining(int $fallback): int
    {
        $left = $this->clock_seconds_left ?? $fallback;

        // A decided contest's clock is stopped, whatever the stored flag says.
        // Without this a board showing a finished bout keeps counting down from
        // a stale anchor and settles on 00:00, which reads as a contest that
        // ran out of time rather than one that was won.
        if ($this->isDecided() || ! $this->clock_running || $this->clock_updated_at === null) {
            return max(0, $left);
        }

        return max(0, $left - (int) $this->clock_updated_at->diffInSeconds(now()));
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /**
     * The division this contest belongs to.
     *
     * Declared even though the weight class also reaches it: contest length is
     * set here, and a bout that has lost its weight class to a mid-competition
     * edit should still be able to say how long it runs for.
     *
     * @return BelongsTo<AgeCategory, $this>
     */
    public function ageCategory(): BelongsTo
    {
        return $this->belongsTo(AgeCategory::class);
    }

    /** @return BelongsTo<WeightCategory, $this> */
    public function weightCategory(): BelongsTo
    {
        return $this->belongsTo(WeightCategory::class);
    }

    /** @return BelongsTo<Athlete, $this> */
    public function athleteA(): BelongsTo
    {
        return $this->belongsTo(Athlete::class, 'athlete_a_id');
    }

    /** @return BelongsTo<Athlete, $this> */
    public function athleteB(): BelongsTo
    {
        return $this->belongsTo(Athlete::class, 'athlete_b_id');
    }

    /** @return BelongsTo<Athlete, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Athlete::class, 'winner_athlete_id');
    }

    /** @return BelongsTo<Court, $this> */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * The bout this one's winner walks into. Null for a final.
     *
     * @return BelongsTo<self, $this>
     */
    public function nextBout(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_bout_id');
    }

    /**
     * The one or two bouts that feed this one. Empty for round 1.
     *
     * @return HasMany<self, $this>
     */
    public function previousBouts(): HasMany
    {
        return $this->hasMany(self::class, 'next_bout_id');
    }

    /** @return HasMany<BoutEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BoutEvent::class)->orderBy('created_at');
    }

    public function isDecided(): bool
    {
        return $this->winner_athlete_id !== null;
    }

    /**
     * Is this contest stopped for jazzo right now?
     *
     * Called and not yet resumed. The two timestamps are kept rather than one
     * flag because the board's yellow box belongs on screen only while this is
     * true, and a contest that was stopped and restarted must not be stopped
     * again at the same halfway mark.
     */
    public function isInJazzo(): bool
    {
        return $this->jazzo_called_at !== null
            && $this->jazzo_resumed_at === null
            && ! $this->isDecided();
    }

    /** Both athletes present and no winner yet — this one can actually be fought. */
    public function isReadyToFight(): bool
    {
        return $this->athlete_a_id !== null
            && $this->athlete_b_id !== null
            && $this->winner_athlete_id === null;
    }

    public function loserId(): ?int
    {
        if (! $this->isDecided()) {
            return null;
        }

        return $this->winner_athlete_id === $this->athlete_a_id
            ? $this->athlete_b_id
            : $this->athlete_a_id;
    }

    /**
     * Phase name derived from how far this round sits from the final, so it
     * stays correct for any bracket size instead of being looked up by
     * athlete count.
     */
    public function phase(int $totalRounds): string
    {
        return match ($totalRounds - $this->round) {
            0 => 'Final',
            1 => 'Semi Final',
            2 => '1/4 Final',
            3 => '1/8 Final',
            4 => '1/16 Final',
            5 => '1/32 Final',
            default => "Round {$this->round}",
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReadyToFight(Builder $query): Builder
    {
        return $query->whereNotNull('athlete_a_id')
            ->whereNotNull('athlete_b_id')
            ->whereNull('winner_athlete_id');
    }
}
