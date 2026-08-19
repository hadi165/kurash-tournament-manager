<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\MedalTable;

/**
 * One certificate per medallist, in placing order.
 *
 * Built from the same MedalTable derivation the standings and the results
 * report use, so a certificate cannot name someone the medal table does not.
 * The alternative — a list typed up after the ceremony — is how the wrong name
 * ends up on the paperwork.
 */
class CertificateSheet
{
    public function __construct(
        private readonly Championship $championship,
        private readonly MedalTable $medals,
        private readonly ?WeightCategory $only = null,
    ) {}

    public function filename(): string
    {
        $base = 'Certificates-'.$this->championship->title;

        return $this->only === null ? $base : $base.' '.$this->only->exportName();
    }

    /**
     * @return list<array{athlete: Athlete, place: int, medal: string, category: WeightCategory}>
     */
    public function certificates(): array
    {
        $categories = $this->only !== null
            ? collect([$this->only])
            : WeightCategory::whereHas(
                'ageCategory',
                fn ($q) => $q->where('championship_id', $this->championship->id)
            )->with('ageCategory')->get();

        $out = [];

        foreach ($categories as $category) {
            $podium = $this->medals->forCategory($category);

            if (! $podium['decided']) {
                continue;
            }

            foreach ([['gold', 1], ['silver', 2]] as [$medal, $place]) {
                if ($podium[$medal] instanceof Athlete) {
                    $out[] = [
                        'athlete' => $podium[$medal],
                        'place' => $place,
                        'medal' => $medal,
                        'category' => $category,
                    ];
                }
            }

            // Two bronzes, both third — there is no fourth place on a kurash
            // podium and no play-off to separate them.
            foreach ($podium['bronze'] as $athlete) {
                $out[] = [
                    'athlete' => $athlete,
                    'place' => 3,
                    'medal' => 'bronze',
                    'category' => $category,
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [
            'championship' => $this->championship,
            'certificates' => $this->certificates(),
        ];
    }
}
