<?php

namespace App\Exports;

use App\Models\Championship;
use App\Support\Noc;

/**
 * Entry counts per national Olympic committee — specification §6.1.
 *
 * Counted from athlete rows rather than a stored total, so it cannot drift from
 * the registration list.
 */
class EntriesByNocReport implements HasTotal, Report
{
    public function __construct(private readonly Championship $championship) {}

    public function title(): string
    {
        return 'Number of Entries by NOC';
    }

    public function filename(): string
    {
        return 'Entries-by-NOC-'.str($this->championship->title)->slug();
    }

    public function meta(): array
    {
        return [
            'Competition' => $this->championship->title,
            'Total entries' => (string) $this->championship->athletes()->count(),
        ];
    }

    public function headings(): array
    {
        return ['NOC', 'Country', 'Male', 'Female', 'Weighed in', 'Total entries'];
    }

    /** @var list<list<string|int|float|null>>|null */
    private ?array $memo = null;

    public function rows(): array
    {
        return $this->memo ??= $this->build();
    }

    /** @return list<list<string|int|float|null>> */
    private function build(): array
    {
        $rows = [];

        foreach ($this->championship->athletes()->get()->groupBy('noc_code') as $noc => $group) {
            $rows[] = [
                Noc::normalise((string) $noc),
                $group->first()?->noc_name,
                $group->where('gender', 'M')->count(),
                $group->where('gender', 'F')->count(),
                $group->where('weighin_status', 'pass')->count(),
                $group->count(),
            ];
        }

        // Largest delegations first, which is the order these tables are read
        // in, then alphabetically so equal-sized ones are findable.
        usort($rows, fn (array $a, array $b) => [$b[5], $a[0]] <=> [$a[5], $b[0]]);

        return $rows;
    }

    public function total(): array
    {
        return [
            'label' => 'Total entries',
            'value' => array_sum(array_map(fn (array $row) => (int) $row[5], $this->rows())),
        ];
    }
}
