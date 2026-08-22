<?php

namespace App\Support;

use App\Models\AgeCategory;
use App\Models\Bout;
use App\Models\Championship;
use Illuminate\Database\Eloquent\Builder;

/**
 * What one bout list is narrowed to.
 *
 * Divisions are named for the competition they belong to — "Men Senior",
 * "Women Senior" — so a separate men/women control would ask the same question
 * twice. The two competitions live in the same list as the divisions instead,
 * above them, and this resolves whichever was picked.
 */
final readonly class DivisionFilter
{
    public const ALL = '';

    public const MEN = 'M';

    public const WOMEN = 'F';

    private function __construct(
        public string $token,
        private ?AgeCategory $division,
    ) {}

    /**
     * @param  string|null  $token  '' for everything, 'M' or 'F' for a whole
     *                              competition, otherwise a division's id.
     */
    public static function for(Championship $championship, ?string $token): self
    {
        $token = (string) $token;

        if ($token === self::MEN || $token === self::WOMEN) {
            return new self($token, null);
        }

        // Resolved through the championship's own divisions, so an id from
        // another competition widens to everything rather than printing
        // someone else's running order.
        $division = ctype_digit($token)
            ? $championship->ageCategories()->find((int) $token)
            : null;

        return $division === null
            ? new self(self::ALL, null)
            : new self($token, $division);
    }

    /**
     * Narrows in the query, not in the row: a women's running order must not
     * carry the men's bouts in the payload with the rows merely hidden.
     *
     * @param  Builder<Bout>  $query
     * @return Builder<Bout>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->division !== null) {
            return $query->where('age_category_id', $this->division->id);
        }

        if ($this->token === self::ALL) {
            return $query;
        }

        return $query->whereHas(
            'weightCategory',
            fn (Builder $category) => $category->where('gender', $this->token)
        );
    }

    /** How the sheet names what it covers. */
    public function label(): string
    {
        return match (true) {
            $this->division !== null => $this->division->name,
            $this->token === self::MEN => 'Men',
            $this->token === self::WOMEN => 'Women',
            default => 'All divisions',
        };
    }

    public function isEverything(): bool
    {
        return $this->token === self::ALL;
    }
}
