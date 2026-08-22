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
        'scoreboard_base_url', 'scoreboard_api_key', 'is_active', 'finish_sound',
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

    /**
     * The sound this mat ends a contest on, as a path under public/.
     *
     * Resolved against the configured list rather than trusted from the row:
     * a file removed from the venue would otherwise leave a mat pointing at
     * something that no longer exists, and a silent buzzer is a bug nobody
     * notices until the moment it matters.
     */
    public function finishSound(): ?string
    {
        $configured = config('scoreboard.finish_sounds');
        $choices = is_array($configured) ? $configured : [];

        if ($this->finish_sound !== null && isset($choices[$this->finish_sound])) {
            return $this->finish_sound;
        }

        $default = (string) config('scoreboard.finish_sound');

        return $default === '' ? null : $default;
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
