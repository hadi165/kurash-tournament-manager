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
        $athlete->load(['weightCategory', 'ageCategory', 'championship']);

        // One card, and the same rule as a batch: an athlete whose age has not
        // been established is not credentialled. Here it empties the run, and
        // the controller answers with the reason rather than handing over a
        // blank page.
        return new self(
            $athlete->championship,
            self::credentialled($athlete) ? collect([$athlete]) : collect(),
            $athlete->fullname,
        );
    }

    /** Is there anything to print? */
    public function isEmpty(): bool
    {
        return $this->athletes->isEmpty();
    }

    /**
     * May this athlete be given a credential?
     *
     * Two conditions, and the second is not implied by the first. The entry
     * must not break the age rules — and there must be a date of birth behind
     * that answer at all.
     *
     * The second is what stops "no rule covers this" being mistaken for
     * "checked and fine". A championship held before the earliest policy on
     * file, or run in an age group an organizer named themselves, comes back
     * from the policy as allowed-but-unjudged; those entries still print,
     * because there is no rule they fail. An athlete nobody has recorded a
     * birth date for does not, whatever year it is, because a card is the
     * document that says the organisers established who this person is.
     */
    private static function credentialled(Athlete $athlete): bool
    {
        return $athlete->hasDateOfBirth() && $athlete->ageVerdict()->eligible;
    }

    /**
     * The athletes a batch of cards is printed for.
     *
     * Athletes whose age has not been established are left out. A credential
     * is the document that admits somebody to the venue, and the IKA rule is
     * that an athlete who does not meet the age requirements is not admitted
     * to competition — so a card printed for an entry nobody has checked
     * asserts something the organisers have not established.
     *
     * Left out rather than refused for the whole batch, because these are
     * printed in hundreds the day before an event: stopping the entire run
     * over one incomplete entry would mean nobody gets a card. Who was left
     * out is reported by excluded(), and the registration screen marks the
     * same athletes so the desk can fix them before printing again.
     *
     * @param  HasMany<Athlete, *>  $relation
     * @return Collection<int, Athlete>
     */
    private static function query($relation): Collection
    {
        return $relation->with(['weightCategory', 'ageCategory', 'championship'])
            ->orderBy('noc_code')
            ->orderBy('fullname')
            ->get()
            ->filter(fn (Athlete $athlete) => self::credentialled($athlete))
            ->values();
    }

    /**
     * Whoever this batch could not print a card for, and why.
     *
     * @return Collection<int, array{athlete: Athlete, reason: string}>
     */
    public function excluded(): Collection
    {
        $printed = $this->athletes->pluck('id')->all();

        return $this->championship->athletes()
            ->with(['ageCategory', 'championship'])
            ->when($this->athletes->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $printed))
            ->orderBy('noc_code')
            ->orderBy('fullname')
            ->get()
            ->map(fn (Athlete $athlete) => ['athlete' => $athlete, 'verdict' => $athlete->ageVerdict()])
            ->filter(fn (array $row) => ! self::credentialled($row['athlete']))
            ->map(fn (array $row) => [
                'athlete' => $row['athlete'],
                'reason' => (string) ($row['verdict']->reason ?? __('Age not verified.')),
            ])
            ->values();
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
