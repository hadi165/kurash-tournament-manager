<?php

namespace App\Models;

use Database\Factories\AthleteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
     * The next accreditation number in a championship.
     *
     * Three digits from 001, counted within the event rather than across the
     * system: a number is read off a card by a person at a door, and every
     * championship starts again at one.
     *
     * Counted on the championship rather than read off the athletes already
     * entered. Taking the highest in use would hand the next arrival the
     * number of somebody who withdrew, and a card printed before they pulled
     * out is still a card.
     *
     * The row is locked because there is always exactly one of it — including
     * for the first athlete of an event, where two simultaneous registrations
     * would otherwise both be told they are number one.
     */
    public static function nextIkaId(int $championshipId): string
    {
        $championship = DB::table('championships')->where('id', $championshipId)->lockForUpdate()->first();

        $next = (int) ($championship->athletes_numbered ?? 0) + 1;

        DB::table('championships')->where('id', $championshipId)
            ->update(['athletes_numbered' => $next]);

        return 'IKA'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Register an athlete and give them their IKA ID.
     *
     * One statement now. The number used to contain the primary key, so it
     * could only be written after the insert and a placeholder had to hold the
     * NOT NULL UNIQUE column open in between; counted within the championship
     * it is known before the row exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function register(array $attributes): self
    {
        return DB::transaction(function () use ($attributes) {
            $championshipId = (int) ($attributes['championship_id'] ?? 0);

            return static::create($attributes + [
                'ika_id' => static::nextIkaId($championshipId),
            ]);
        });
    }

    public function label(): string
    {
        return "{$this->fullname} ({$this->noc_code})";
    }
}
