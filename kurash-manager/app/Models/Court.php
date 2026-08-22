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
        'scoreboard_base_url', 'scoreboard_api_key', 'is_active', 'finish_sound', 'finish_sound_enabled',
    ];

    /**
     * A column default is applied by the database on insert, which the model
     * that did the inserting never sees. Stated here as well, so a mat is
     * switched on the moment it exists rather than from the next read.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'finish_sound_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'finish_sound_enabled' => 'boolean',
            // Encrypted at rest: a database dump should not hand over the keys
            // to every scoreboard in the venue.
            'scoreboard_api_key' => 'encrypted',
        ];
    }

    /**
     * What this mat sounds at the end of a contest, or null for nothing.
     *
     * The one question the buzzer asks, so that a mat switched off renders no
     * sound and no prompt to enable one rather than a silent player nobody can
     * tell from a broken one.
     */
    public function finishSound(): ?string
    {
        return $this->finish_sound_enabled ? $this->finishSoundFile() : null;
    }

    /**
     * The file this mat would use, whether or not it is switched on. What the
     * chooser offers and the preview plays: auditioning a buzzer is a
     * reasonable thing to do before turning it on.
     *
     * Resolved against the configured list rather than trusted from the row:
     * a file removed from the venue would otherwise leave a mat pointing at
     * something that no longer exists, and a silent buzzer is a bug nobody
     * notices until the moment it matters.
     */
    public function finishSoundFile(): ?string
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
