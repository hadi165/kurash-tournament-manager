<?php

namespace App\Models;

use Database\Factories\CourtFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    /** @use HasFactory<CourtFactory> */
    use HasFactory;

    protected $fillable = [
        'championship_id', 'number', 'name',
        'scoreboard_base_url', 'scoreboard_api_key', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Encrypted at rest: a database dump should not hand over the keys
            // to every scoreboard in the venue.
            'scoreboard_api_key' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /** @return HasMany<Bout, $this> */
    public function bouts(): HasMany
    {
        return $this->hasMany(Bout::class);
    }

    public function label(): string
    {
        return $this->name ?: __('Mat :n', ['n' => $this->number]);
    }
}
