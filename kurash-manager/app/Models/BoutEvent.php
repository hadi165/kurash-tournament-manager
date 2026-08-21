<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One row of a contest's history.
 *
 * The whole result is recalculated from these rather than read off a counter,
 * so a row has to carry enough to be replayed: which side, what was called,
 * whether it added or removed, where the score came from, what caused it, and
 * where it sits in the order.
 *
 * Three columns look similar and are not:
 *
 *   action        the kind of record — scored, score_voided, result_recorded,
 *                 advanced, jazzo, resumed
 *   entry_action  ADD | REMOVE | CORRECT, what it does to the tally
 *   source        the channel that entered it — operator, scoreboard, system
 *   origin        where a score came from — TECHNIQUE, MANUAL, AUTO_FROM_T,
 *                 AUTO_FROM_D
 *
 * `before` and `after` are json columns turned into arrays by casts(). Static
 * analysis reads the migration and sees strings, so the shape is declared here
 * — the same reason Championship declares its date columns.
 *
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $competitor_side
 * @property string|null $event_type
 * @property string|null $entry_action
 * @property string|null $origin
 * @property int|null $parent_event_id
 * @property int|null $sequence_number
 */
class BoutEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'bout_id', 'user_id', 'competitor_side', 'event_type', 'entry_action',
        'action', 'source', 'origin', 'parent_event_id', 'sequence_number',
        'before', 'after',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * Append a row with the next sequence number for its bout.
     *
     * created_at cannot order a contest. Two calls a referee makes inside the
     * same second are indistinguishable by it, and the rules turn on order —
     * which chala a dakki supersedes, which side was warned most recently. The
     * number is taken under a row lock so two screens on the same mat cannot
     * both claim it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createInSequence(array $attributes): self
    {
        return DB::transaction(function () use ($attributes): self {
            $attributes['sequence_number'] ??= 1 + (int) static::query()
                ->where('bout_id', $attributes['bout_id'])
                ->lockForUpdate()
                ->max('sequence_number');

            return static::create($attributes);
        });
    }

    /** @return BelongsTo<Bout, $this> */
    public function bout(): BelongsTo
    {
        return $this->belongsTo(Bout::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The row that caused this one — the tanbeh behind an automatic chala.
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    /**
     * What this row caused. Taking a penalty back takes these with it.
     *
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id');
    }
}
