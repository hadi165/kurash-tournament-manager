<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `before` and `after` are json columns turned into arrays by casts(). Static
 * analysis reads the migration and sees strings, so the shape is declared here
 * — the same reason Championship declares its date columns.
 *
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
class BoutEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['bout_id', 'user_id', 'action', 'source', 'before', 'after'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
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
}
