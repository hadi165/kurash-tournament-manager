<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who closed a championship, who reopened it, and when.
 *
 * The same reasoning as bout_events: a competition that can be reopened after
 * the medals have been given out needs the reopening to be visible, or the
 * archive is only a suggestion.
 */
class ChampionshipEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['championship_id', 'user_id', 'action', 'note'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
