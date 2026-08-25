<?php

namespace App\Exports;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\WeightCategory;
use App\Services\RoundRobinStandings;
use App\Support\Noc;
use App\Support\TournamentFormat;

/**
 * A round robin, ready to be printed or written to a worksheet.
 *
 * The counterpart to BracketSheet, and deliberately not a mode of it. A
 * bracket sheet describes a tree — seats, branches, the rows a connector is
 * hung from — and none of that means anything here. Sending a round robin
 * through it to reuse the layout would produce a drawing of a competition that
 * is not being held.
 *
 * What a round robin sheet describes instead is three tables: who was drawn,
 * what everybody plays, and where that leaves them. Both writers read this one
 * description, so the PDF and the spreadsheet cannot disagree.
 */
final class RoundRobinSheet
{
    public function __construct(
        public readonly WeightCategory $category,
        /**
         * Whether the fixtures carry the number the running order gave them.
         * A sheet saved at the end of a draw ceremony does not: the pairings
         * are settled at that point and the schedule is not.
         */
        public readonly bool $fightNumbers = true,
    ) {}

    /** The label every one of these sheets carries, in every format. */
    public function formatLabel(): string
    {
        return TournamentFormat::RoundRobin->label();
    }

    /** Was this class run this way against the IKA rule? Round robin never is. */
    public function isOverride(): bool
    {
        return $this->category->formatWasOverridden();
    }

    /**
     * The field, in draw order.
     *
     * @return list<array{draw:int|null, name:string, noc:string, ika:string}>
     */
    public function athletes(): array
    {
        $rows = [];

        foreach ($this->category->numberedAthletes()->get() as $athlete) {
            $rows[] = [
                'draw' => $athlete->draw_number === null ? null : (int) $athlete->draw_number,
                'name' => (string) $athlete->fullname,
                'noc' => (string) Noc::normalise($athlete->noc_code),
                'ika' => (string) $athlete->ika_id,
            ];
        }

        return $rows;
    }

    /**
     * Every fixture, round by round.
     *
     * A round is a grouping of the schedule and nothing more: losing one puts
     * nobody out, which is why these carry no notion of a phase.
     *
     * @return array<int, list<array{fight:string, a:string, aNoc:string, b:string, bNoc:string, winner:string, result:string, decided:bool}>>
     */
    public function rounds(): array
    {
        $rounds = [];

        $bouts = $this->category->bouts()
            ->with(['athleteA', 'athleteB', 'winner'])
            ->orderBy('round')
            ->orderBy('position_in_round')
            ->get();

        foreach ($bouts as $bout) {
            $rounds[(int) $bout->round][] = [
                'fight' => $this->fightNumbers && $bout->fight_number ? 'No. '.$bout->fight_number : '',
                'a' => (string) $bout->athleteA?->fullname,
                'aNoc' => (string) Noc::normalise($bout->athleteA?->noc_code),
                'b' => (string) $bout->athleteB?->fullname,
                'bNoc' => (string) Noc::normalise($bout->athleteB?->noc_code),
                'winner' => (string) $bout->winner?->fullname,
                'result' => $this->result($bout),
                'decided' => $bout->winner_athlete_id !== null,
            ];
        }

        return $rounds;
    }

    /** How a contest was won, in the words the rules use. */
    private function result(Bout $bout): string
    {
        if ($bout->winner_athlete_id === null) {
            return '';
        }

        return $bout->win_type === null ? 'Won' : ucfirst(str_replace('_', ' ', $bout->win_type));
    }

    /**
     * The results matrix: everybody against everybody.
     *
     * The shape an official reads a small group off — one row and one column
     * per athlete, the diagonal blank because nobody meets themselves.
     *
     * @return array{athletes: list<string>, nocs: list<string>, cells: array<int, array<int, string>>}
     */
    public function matrix(): array
    {
        /** @var list<Athlete> $field */
        $field = array_values($this->category->numberedAthletes()->get()->all());
        $size = count($field);

        // Position in the field, so a contest can be placed by its athletes.
        $index = [];

        foreach ($field as $position => $athlete) {
            $index[(int) $athlete->id] = $position;
        }

        $cells = [];

        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                // Nobody meets themselves, so the diagonal is ruled out.
                $cells[$row][$column] = $row === $column ? '—' : '';
            }
        }

        foreach ($this->category->bouts()->get() as $bout) {
            $a = $index[(int) $bout->athlete_a_id] ?? null;
            $b = $index[(int) $bout->athlete_b_id] ?? null;

            if ($a === null || $b === null) {
                continue;
            }

            // Read row-against-column: W in the winner's row, L in the loser's.
            [$forA, $forB] = match (true) {
                $bout->winner_athlete_id === $bout->athlete_a_id => ['W', 'L'],
                $bout->winner_athlete_id === $bout->athlete_b_id => ['L', 'W'],
                default => ['·', '·'],
            };

            $cells[$a][$b] = $forA;
            $cells[$b][$a] = $forB;
        }

        $names = [];
        $nocs = [];

        foreach ($field as $athlete) {
            $names[] = (string) $athlete->fullname;
            $nocs[] = (string) Noc::normalise($athlete->noc_code);
        }

        return ['athletes' => $names, 'nocs' => $nocs, 'cells' => $cells];
    }

    /**
     * The table, from the one service that computes it.
     *
     * @return array<string, mixed>
     */
    public function standings(): array
    {
        return app(RoundRobinStandings::class)->forCategory($this->category);
    }

    /**
     * How ties were handled, stated on the sheet rather than left implicit.
     *
     * A results sheet that ranks two athletes level on wins without saying
     * what separated them is a sheet nobody can check.
     *
     * @return list<string>
     */
    public function tieBreakNotes(): array
    {
        /** @var list<string> $chain */
        $chain = (array) config('kurash.round_robin.tie_breaks', []);

        $names = [
            'wins' => __('contests won'),
            'points' => __('ranking points'),
            'head_to_head' => __('the contest between two tied athletes'),
            'mini_table' => __('a table among the tied athletes'),
            'match_time' => __('match time'),
            'referee' => __('technical or referee decision'),
        ];

        $notes = [];

        foreach ($chain as $step) {
            if ($step === 'match_time' && config('kurash.round_robin.match_time') === 'disabled') {
                continue;
            }

            $notes[] = $names[$step] ?? $step;
        }

        return $notes;
    }

    /** A win is worth this, and a loss this — the sheet says so out loud. */
    public function pointsNote(): string
    {
        return __('Win :win point(s), loss :loss.', [
            'win' => (int) config('kurash.round_robin.points.win', 1),
            'loss' => (int) config('kurash.round_robin.points.loss', 0),
        ]);
    }

    public function filename(): string
    {
        return 'Round-robin-'.$this->category->exportName();
    }

    /** @return array<string, string> */
    public function meta(): array
    {
        $championship = $this->category->ageCategory->championship;

        return array_filter([
            'Competition' => $championship->title,
            'Category' => $this->category->ageCategory->name,
            'Gender / Weight Category' => $this->category->exportName(),
            'Format' => $this->formatLabel(),
            'Athletes' => (string) count($this->athletes()),
            'Venue' => $championship->location,
            'Date' => $championship->starts_on?->format('j M Y'),
        ]);
    }
}
