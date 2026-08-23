<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Championship;
use App\Support\Gender;
use App\Support\Noc;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everyone entered in a championship, by nation.
 *
 * Not a competition document. This is the list the hotel and the organising
 * team work from — who is coming, from where, and how to tell one delegation's
 * rooms from another's — so it is ordered by country and then by name rather
 * than by weight class, and it carries the accreditation number a card is
 * checked against at a door.
 *
 * Narrowed to one nation when a delegation asks for their own, which is the
 * other half of the same job.
 */
class AthleteListReport implements HasTotal, Report
{
    /**
     * @param  string|null  $noc  One nation's list, or null for everybody's,
     *                            in which case the countries follow each other
     *                            in order.
     */
    public function __construct(
        private readonly Championship $championship,
        private readonly ?string $noc = null,
    ) {}

    public function title(): string
    {
        return $this->noc === null
            ? 'Athlete list'
            : 'Athlete list — '.$this->nation();
    }

    public function filename(): string
    {
        $name = 'Athletes-'.str($this->championship->title)->slug();

        return $this->noc === null ? $name : $name.'-'.str($this->nation())->slug();
    }

    public function meta(): array
    {
        return array_filter([
            'Competition' => $this->championship->title,
            'Location' => $this->championship->location ?? '—',
            // Said on the sheet as well as in the filename: a printout that
            // reaches a hotel desk has to say whose list it is.
            'Delegation' => $this->noc === null ? 'All countries' : $this->nation(),
        ]);
    }

    /** "UZB — Uzbekistan", or the code alone if it is one we do not know. */
    private function nation(): string
    {
        $code = Noc::normalise($this->noc) ?? '';
        $name = Noc::name($code);

        return $name === null ? $code : $code.' — '.$name;
    }

    public function headings(): array
    {
        // NOC and Country in their own columns rather than one: a spreadsheet
        // handed to a hotel gets sorted and filtered on them, and a column
        // reading "UZB — Uzbekistan" cannot be either.
        return ['NOC', 'Country', 'IKA ID', 'Name', 'Gender', 'Division', 'Weight', 'Passport / ID', 'Club'];
    }

    public function total(): array
    {
        return ['label' => 'Athletes', 'value' => $this->athletes()->count()];
    }

    public function rows(): array
    {
        $rows = [];

        foreach ($this->athletes() as $athlete) {
            $code = Noc::normalise($athlete->noc_code);

            $rows[] = [
                $code,
                // What the athlete's own entry says, falling back to the code
                // table — a delegation occasionally enters under a name of
                // its own, and the sheet should say what they called it.
                $athlete->noc_name ?: Noc::name($code),
                $athlete->ika_id,
                $athlete->fullname,
                Gender::label($athlete->gender),
                $athlete->ageCategory?->name,
                $athlete->weightCategory?->label,
                $athlete->national_id,
                $athlete->club,
            ];
        }

        return $rows;
    }

    /** @return Collection<int, Athlete> */
    private function athletes(): Collection
    {
        $code = Noc::normalise($this->noc);

        return $this->championship->athletes()
            ->when($code !== null, fn ($q) => $q->where('noc_code', $code))
            ->with(['ageCategory', 'weightCategory'])
            // By nation first, which is what makes an unfiltered sheet read as
            // one delegation after another rather than as a queue.
            ->orderBy('noc_code')
            ->orderBy('fullname')
            ->get();
    }
}
