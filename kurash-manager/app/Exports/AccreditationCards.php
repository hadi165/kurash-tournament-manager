<?php

namespace App\Exports;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Support\QrCode;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Entrance cards for a delegation, a category, or one athlete.
 *
 * The QR encodes the federation ID and nothing else. It is scanned at a door by
 * a volunteer with a phone, so the payload has to be meaningful when the
 * network is not — and putting a name or an access list in the code itself
 * would mean a reprint every time either changed.
 */
class AccreditationCards
{
    /** Access zones a card can grant, and what each one opens. */
    public const ZONES = [
        1 => 'Accreditation and registration',
        2 => 'Warm-up and changing areas',
        3 => 'Field of play',
        4 => 'Athlete seating and mixed zone',
        5 => 'Officials and organising committee',
    ];

    /** @param  Collection<int, Athlete>  $athletes */
    public function __construct(
        private readonly Championship $championship,
        private readonly Collection $athletes,
        private readonly string $scope,
    ) {}

    public static function forChampionship(Championship $championship): self
    {
        return new self($championship, self::query($championship->athletes()), $championship->title);
    }

    public static function forCategory(AgeCategory $ageCategory): self
    {
        return new self(
            $ageCategory->championship,
            self::query($ageCategory->athletes()),
            $ageCategory->name,
        );
    }

    public static function forAthlete(Athlete $athlete): self
    {
        return new self(
            $athlete->championship,
            collect([$athlete->load('weightCategory')]),
            $athlete->fullname,
        );
    }

    /**
     * @param  HasMany<Athlete, *>  $relation
     * @return Collection<int, Athlete>
     */
    private static function query($relation): Collection
    {
        return $relation->with('weightCategory')->orderBy('noc_code')->orderBy('fullname')->get();
    }

    public function filename(): string
    {
        return 'Accreditation-'.$this->scope;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [
            'championship' => $this->championship,
            'zones' => self::ZONES,
            'cards' => $this->athletes->map(fn (Athlete $athlete) => [
                'athlete' => $athlete,
                'qr' => QrCode::dataUri($athlete->ika_id, 132),
                // Athletes get the competitor zones unless someone has set
                // otherwise. Zone 3 — the field of play — is never granted by
                // default, whatever the position.
                'areas' => $this->areasFor($athlete),
            ])->all(),
        ];
    }

    /** @return list<int> */
    private function areasFor(Athlete $athlete): array
    {
        $areas = $athlete->accreditation_areas ?? [];

        if ($areas !== []) {
            return array_values(array_map('intval', $areas));
        }

        return [1, 2, 4];
    }
}
