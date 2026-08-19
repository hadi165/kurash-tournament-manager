<?php

namespace App\Exports;

use App\Models\Championship;
use App\Services\MedalTable;
use App\Support\Noc;

/**
 * Medal standing by NOC — specification §9.3.
 *
 * Ranked the way standings always are: gold first, then silver, then bronze,
 * so a country with one gold finishes above a country with three bronzes.
 */
class MedalStandingReport implements Report
{
    public function __construct(
        private readonly Championship $championship,
        private readonly MedalTable $medals,
    ) {}

    public function title(): string
    {
        return 'Medal Standing';
    }

    public function filename(): string
    {
        return 'Medal-Standing-'.str($this->championship->title)->slug();
    }

    public function meta(): array
    {
        return [
            'Competition' => $this->championship->title,
            'Location' => $this->championship->location ?? '—',
        ];
    }

    public function headings(): array
    {
        return ['Rank', 'NOC', 'Gold', 'Silver', 'Bronze', 'Total'];
    }

    public function rows(): array
    {
        $rows = [];
        $rank = 0;
        $previous = null;

        foreach ($this->medals->standings($this->championship->id) as $index => $row) {
            $key = [$row['gold'], $row['silver'], $row['bronze']];

            // Equal tallies share a rank, and the next country takes the
            // position it would have had — standard standings behaviour.
            if ($key !== $previous) {
                $rank = $index + 1;
                $previous = $key;
            }

            $rows[] = [$rank, Noc::normalise($row['noc_code']), $row['gold'], $row['silver'], $row['bronze'], $row['total']];
        }

        return $rows;
    }
}
