<?php

namespace App\Models;

use Database\Factories\AthleteFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /*
     |--------------------------------------------------------------------------
     | The scale
     |--------------------------------------------------------------------------
     |
     | The three states of weighin_status, named once. The strings are the
     | values in the enum column and are read back for the life of a
     | championship, so they are part of the schema and are not renamed.
     */

    /** Registered, not yet weighed. Not admitted to competition. */
    public const WEIGHIN_PENDING = 'pending';

    /** Weighed, inside the class. The only state that competes. */
    public const WEIGHIN_PASS = 'pass';

    /** Weighed, outside the class. Not admitted to competition. */
    public const WEIGHIN_FAIL = 'fail';

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

    /*
     |--------------------------------------------------------------------------
     | Admission to competition
     |--------------------------------------------------------------------------
     |
     | The IKA rule is short: an athlete who has not been weighed must not be
     | admitted to competition, and one who was weighed outside their class is
     | not in that class. Both are the single condition below —
     | weighin_status = 'pass' — and it is written here once so that the draw
     | screen, the generators, the format policy and the tests cannot each
     | arrive at a slightly different reading of it.
     |
     | "Not fail" is NOT that condition, and was the bug this replaces: an
     | athlete nobody has weighed is pending, not passed, and pending is
     | exactly who the rule keeps off the mat.
     |
     | https://kurash-ika.org/2022/08/20/kurash-rules/
     */

    /** Has this athlete been weighed and admitted to their class? */
    public function passedWeighIn(): bool
    {
        return $this->weighin_status === self::WEIGHIN_PASS;
    }

    /**
     * Narrow a query to the athletes who may compete.
     *
     * @param  Builder<Athlete>  $query
     * @return Builder<Athlete>
     */
    public function scopePassedWeighIn(Builder $query): Builder
    {
        return $query->where('weighin_status', self::WEIGHIN_PASS);
    }

    /**
     * Narrow a query to the athletes who may NOT compete.
     *
     * The complement of the scope above rather than a list of the other two
     * states, so a fourth state added to the enum later is refused admission
     * by default instead of being quietly let through.
     *
     * @param  Builder<Athlete>  $query
     * @return Builder<Athlete>
     */
    public function scopeFailedOrPendingWeighIn(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('weighin_status', '!=', self::WEIGHIN_PASS)
                ->orWhereNull('weighin_status');
        });
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

    /**
     * Where this athlete sits when an entry list is read.
     *
     * By accreditation number, which is the number on the card at the door and
     * the one an official reads down a list looking for somebody. Never by draw
     * number: a draw number says where an athlete stands in the bracket, and
     * sorting the list by it turns a register into a running order.
     *
     * IKA001 through IKA999 sort correctly as text; a thousandth athlete would
     * not, so the digits are compared as a number. Somebody with no number yet
     * goes to the foot, then by name and then by id, so the same list read
     * twice comes back in the same order.
     *
     * One comparator for the screen, the PDF and the workbook — a list that
     * disagrees with its own export is worse than either.
     *
     * @return array{int, int, string, int}
     */
    public function entryOrder(): array
    {
        $number = preg_replace('/\D+/', '', (string) $this->ika_id);

        return [
            $number === '' ? 1 : 0,
            $number === '' ? 0 : (int) $number,
            mb_strtolower((string) $this->fullname),
            (int) $this->id,
        ];
    }
}
