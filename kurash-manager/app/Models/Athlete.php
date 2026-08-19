<?php

namespace App\Models;

use Database\Factories\AthleteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * weight_category_id is nullable — an athlete is registered before they are
 * placed in a class, and deleting a class nulls the column rather than removing
 * the athlete. Callers must treat the relation as optional; check the foreign
 * key, since static analysis types the accessor itself as non-null.
 *
 * accreditation_areas is a json column turned into an array by casts(); static
 * analysis reads the migration and sees a string, so the shape is declared here.
 *
 * @property-read WeightCategory|null $weightCategory
 * @property array<int, int|string>|null $accreditation_areas
 */
class Athlete extends Model
{
    /** @use HasFactory<AthleteFactory> */
    use HasFactory;

    protected $fillable = [
        'ika_id', 'championship_id', 'age_category_id', 'weight_category_id',
        'fullname', 'gender', 'noc_code', 'noc_name', 'national_id', 'club', 'photo_url',
        'position_title', 'accreditation_areas',
        'weighin_kg', 'weighin_status', 'weighin_at',
        'draw_number', 'draw_number_source',
    ];

    protected function casts(): array
    {
        return [
            'weighin_kg' => 'decimal:2',
            'weighin_at' => 'datetime',
            'accreditation_areas' => 'array',
        ];
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /** @return BelongsTo<AgeCategory, $this> */
    public function ageCategory(): BelongsTo
    {
        return $this->belongsTo(AgeCategory::class);
    }

    /** @return BelongsTo<WeightCategory, $this> */
    public function weightCategory(): BelongsTo
    {
        return $this->belongsTo(WeightCategory::class);
    }

    /**
     * IKA IDs are derived from the primary key, so they can only be assigned
     * after the row exists — same rule as the original system.
     */
    public static function assignIkaId(self $athlete): string
    {
        $id = 'IKA'.str_pad((string) $athlete->getKey(), 6, '0', STR_PAD_LEFT);
        $athlete->forceFill(['ika_id' => $id])->save();

        return $id;
    }

    /**
     * Register an athlete and give them their IKA ID.
     *
     * Two statements, because the ID contains the primary key and the column is
     * NOT NULL UNIQUE — so a placeholder has to occupy it for the length of the
     * insert. Both run in one transaction, and the placeholder is sized to fit
     * the column rather than overflowing it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function register(array $attributes): self
    {
        return DB::transaction(function () use ($attributes) {
            $athlete = static::create($attributes + [
                'ika_id' => 'TMP'.Str::upper(Str::random(13)),   // exactly 16 chars
            ]);

            static::assignIkaId($athlete);

            return $athlete->refresh();
        });
    }

    public function label(): string
    {
        return "{$this->fullname} ({$this->noc_code})";
    }
}
