<?php

/**
 * The round robin as it is actually worked: who may choose it, who may depart
 * from it, what the screens show, what the sheets print, and what an upgrade
 * does to competitions that were drawn before any of this existed.
 *
 * The format resolution itself is in TournamentFormatTest and the table in
 * RoundRobinStandingsTest; these are the paths around them.
 */

use App\Livewire\Competition\Bracket as BracketScreen;
use App\Livewire\Operator\Presentation;
use App\Models\Bout;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BracketGenerator;
use App\Services\DrawGenerator;
use App\Services\FightOrderScheduler;
use App\Services\RoundRobinGenerator;
use App\Support\BracketSeeding;
use App\Support\TournamentFormat;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->supervisor = User::factory()->create(['role' => 'supervisor']);
    $this->official = User::factory()->create(['role' => 'official']);
});

/** A drawn round robin, ready to be looked at. */
function drawnRoundRobin(int $count = 4, string $label = '-flow'): WeightCategory
{
    [$category] = categoryWithAthletes($count, $label."-{$count}");
    app(DrawGenerator::class)->generate($category);

    return $category->refresh();
}

describe('who may choose a format', function () {
    /** The compliant format is an ordinary competition decision. */
    it('lets a supervisor draw the format the rule gives', function () {
        [$category] = categoryWithAthletes(4, '-super-rr');

        Livewire::actingAs($this->supervisor)
            ->test(BracketScreen::class, ['weightCategory' => $category])
            ->assertSet('format', 'round_robin')
            ->call('generate')
            ->assertHasNoErrors();

        expect($category->refresh()->draw_format)->toBe('round_robin')
            ->and($category->bouts()->count())->toBe(6);
    });

    /**
     * Departing from it is not. A supervisor may draw, publish, lock and
     * delete — and may not sign for a draw that does not follow the rule.
     */
    it('refuses a supervisor the knockout override', function () {
        [$category] = categoryWithAthletes(4, '-super-ko');

        Livewire::actingAs($this->supervisor)
            ->test(BracketScreen::class, ['weightCategory' => $category])
            ->set('format', 'knockout')
            ->set('overrideReason', 'Local rules.')
            ->call('generate')
            ->assertForbidden();

        expect($category->refresh()->bouts()->count())->toBe(0)
            ->and($category->draw_format)->toBeNull();
    });

    it('lets an administrator sign for it', function () {
        [$category] = categoryWithAthletes(4, '-admin-ko');

        Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category])
            ->set('format', 'knockout')
            ->set('overrideReason', 'Local invitational.')
            ->call('generate')
            ->assertHasNoErrors();

        $category->refresh();

        expect($category->draw_format)->toBe('knockout')
            ->and($category->draw_format_override_by)->toBe($this->admin->id)
            ->and($category->draw_format_override_reason)->toBe('Local invitational.');
    });

    /** The warning and the reason box appear before anything is drawn. */
    it('arms the confirmation as soon as knockout is chosen', function () {
        [$category] = categoryWithAthletes(4, '-armed');

        Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category])
            ->assertSet('confirmingOverride', false)
            ->set('format', 'knockout')
            ->assertSet('confirmingOverride', true)
            ->set('format', 'round_robin')
            ->assertSet('confirmingOverride', false)
            // Choosing back also drops a reason typed for a decision abandoned.
            ->assertSet('overrideReason', '');
    });

    it('will not draw the override without a reason', function () {
        [$category] = categoryWithAthletes(4, '-noreason-ui');

        Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category])
            ->set('format', 'knockout')
            ->call('generate');

        expect($category->refresh()->bouts()->count())->toBe(0);
    });

    /**
     * The select is not what is trusted. A hand-edited option asking for a
     * round robin of sixteen is refused on the server.
     */
    it('refuses a format the field does not allow, whatever was posted', function () {
        [$category] = categoryWithAthletes(8, '-posted');

        Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category])
            ->set('format', 'round_robin')
            ->call('generate');

        expect($category->refresh()->bouts()->count())->toBe(0)
            ->and($category->draw_format)->toBeNull();
    });

    /** The selector is only offered where the rule leaves a choice. */
    it('offers no format selector to a field with one lawful shape', function () {
        [$small] = categoryWithAthletes(4, '-choice');
        [$large] = categoryWithAthletes(8, '-nochoice');

        $withChoice = Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $small])->html();

        $without = Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $large])->html();

        expect($withChoice)->toContain('Tournament format')
            ->and($without)->not->toContain('Tournament format');
    });
});

describe('what the screens show', function () {
    it('shows the administrator a table rather than a tree', function () {
        $category = drawnRoundRobin(4, '-adminview');

        $html = Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category])->html();

        expect($html)->toContain('Round robin')
            ->toContain('Standings')
            // The bracket's geometry has no business on this screen.
            ->not->toContain('bkt__slot');
    });

    it('presents a published round robin to an operator without a bracket', function () {
        $category = drawnRoundRobin(4, '-operator');
        $category->forceFill(['draw_published_at' => now()])->save();

        $html = Livewire::actingAs($this->official)
            ->test(Presentation::class, ['weightCategory' => $category->refresh()])
            ->assertOk()
            ->html();

        expect($html)->toContain('Round 1')
            ->and($html)->not->toContain('Semi Final');
    });

    it('shows the hall a fixture list and a table', function () {
        $category = drawnRoundRobin(4, '-hall');

        $this->actingAs($this->official)
            ->get(route('display.bracket', $category))
            ->assertOk()
            ->assertSee('Round Robin')
            ->assertSee('Standings')
            ->assertDontSee('bkt__slot');
    });

    it('shows a single entrant their own screen', function () {
        [$category] = categoryWithAthletes(1, '-solodisplay');
        app(DrawGenerator::class)->generate($category);

        $this->actingAs($this->official)
            ->get(route('display.bracket', $category->refresh()))
            ->assertOk()
            ->assertSee('Single entrant');
    });

    /** The knockout screens must be exactly where they were. */
    it('still shows a bracket for a bracket', function () {
        [$category] = categoryWithAthletes(8, '-stilltree');
        app(DrawGenerator::class)->generate($category);

        $this->actingAs($this->official)
            ->get(route('display.bracket', $category->refresh()))
            ->assertOk()
            ->assertSee('bkt__slot', false);
    });
});

describe('the sheets', function () {
    it('prints a round-robin sheet rather than a bracket', function () {
        $category = drawnRoundRobin(4, '-pdf');
        app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);

        $this->actingAs($this->admin);

        $pdf = $this->get(route('exports.bracket-sheet', [
            'weightCategory' => $category, 'format' => 'pdf',
        ]));

        $pdf->assertOk();

        $content = $pdf->getContent();

        // One page, and a PDF rather than a redirect or an error page.
        expect(substr((string) $content, 0, 4))->toBe('%PDF')
            ->and(preg_match_all('#/Type\s*/Page[^s]#', (string) $content))->toBeGreaterThanOrEqual(1);
    });

    it('writes a worksheet with the draw, the contests and the standings', function () {
        $category = drawnRoundRobin(4, '-xlsx');

        $this->actingAs($this->admin);

        $path = tempnam(sys_get_temp_dir(), 'rr').'.xlsx';

        ob_start();
        $this->get(route('exports.bracket-sheet', [
            'weightCategory' => $category, 'format' => 'xlsx',
        ]))->sendContent();
        file_put_contents($path, ob_get_clean());

        $page = IOFactory::load($path)->getActiveSheet();
        $values = collect($page->toArray())->flatten()->filter()->values();

        expect($values)->toContain('Round Robin')
            ->and($values)->toContain('Draw')
            ->and($values)->toContain('Contests')
            ->and($values)->toContain('Standings')
            // Every athlete in the class appears in it.
            ->and($values->filter(fn ($v) => is_string($v) && str_contains($v, 'Athlete ')))
            ->not->toBeEmpty();

        unlink($path);
    });

    it('refuses a sheet for a class with a single entrant', function () {
        [$category] = categoryWithAthletes(1, '-solosheet');
        app(DrawGenerator::class)->generate($category);

        $this->actingAs($this->admin)
            ->get(route('exports.bracket-sheet', [
                'weightCategory' => $category->refresh(), 'format' => 'pdf',
            ]))
            ->assertNotFound();
    });
});

describe('publication, locking and isolation', function () {
    it('keeps a locked round robin from being redrawn', function () {
        $category = drawnRoundRobin(4, '-locked');
        $codes = $category->bouts()->pluck('play_code')->sort()->values()->all();

        $category->forceFill(['draw_locked_at' => now()])->save();

        Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category->refresh()])
            ->call('generate');

        expect($category->refresh()->bouts()->pluck('play_code')->sort()->values()->all())->toBe($codes);
    });

    it('will not replace a published round robin without saying so', function () {
        $category = drawnRoundRobin(4, '-published');
        $category->forceFill(['draw_published_at' => now()])->save();

        $codes = $category->bouts()->pluck('play_code')->sort()->values()->all();

        Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category->refresh()])
            ->call('generate')
            ->assertSet('confirmingReplacePublished', true);

        expect($category->refresh()->bouts()->pluck('play_code')->sort()->values()->all())->toBe($codes);
    });

    it('refuses an operator a round robin that is not published', function () {
        $category = drawnRoundRobin(4, '-unpublished');

        Livewire::actingAs($this->official)
            ->test(Presentation::class, ['weightCategory' => $category])
            ->assertForbidden();
    });

    /** Drawing one class must not touch another. */
    it('leaves every other class alone', function () {
        $mine = drawnRoundRobin(4, '-mine');
        $theirs = drawnRoundRobin(5, '-theirs');

        $before = $theirs->bouts()->pluck('play_code')->sort()->values()->all();

        app(DrawGenerator::class)->generate($mine->refresh());

        expect($theirs->refresh()->bouts()->pluck('play_code')->sort()->values()->all())->toBe($before)
            ->and($theirs->bouts()->count())->toBe(10)
            ->and($mine->refresh()->bouts()->count())->toBe(6);
    });
});

describe('two administrators pressing Generate at once', function () {
    /**
     * Both generators clear the class and write it again, so two interleaved
     * calls could leave a class holding half of each draw. The row is locked
     * for the length of the transaction, and the unique index on
     * (weight_category_id, round, position_in_round) is the second net.
     */
    it('cannot produce a duplicated draw', function () {
        [$category] = categoryWithAthletes(4, '-concurrent');

        app(DrawGenerator::class)->generate($category);
        app(DrawGenerator::class)->generate($category->refresh());
        app(DrawGenerator::class)->generate($category->refresh());

        $bouts = $category->refresh()->bouts()->get();

        expect($bouts)->toHaveCount(6)
            ->and($bouts->pluck('play_code')->unique())->toHaveCount(6);

        $slots = $bouts->map(fn (Bout $b) => "{$b->round}:{$b->position_in_round}");

        expect($slots->unique())->toHaveCount(6);
    });

    it('refuses a second contest in a slot the draw already holds', function () {
        $category = drawnRoundRobin(4, '-slotclash');
        $first = $category->bouts()->first();

        expect(fn () => Bout::create([
            'play_code' => 'clash-'.$first->id,
            'championship_id' => $first->championship_id,
            'age_category_id' => $first->age_category_id,
            'weight_category_id' => $first->weight_category_id,
            'round' => $first->round,
            'position_in_round' => $first->position_in_round,
            'status' => Bout::STATUS_PENDING,
        ]))->toThrow(QueryException::class);
    });
});

describe('the running order', function () {
    /**
     * A knockout gets its rest from the shape of the bracket. A round robin
     * cannot: an athlete appears in almost every round, and the turn of the
     * circle sometimes brings one of them straight back.
     *
     * The running order is numbered as drawn anyway — round by round and
     * fixture by fixture — because it is the order the printed sheet states
     * and an official has to be able to read the next number off it. What is
     * owed is that the shortfall is named, not shuffled out of sight.
     */
    it('numbers the circle as it was drawn, and names the rest that costs', function () {
        $category = drawnRoundRobin(5, '-rest');
        $championship = $category->ageCategory->championship;

        $scheduler = app(FightOrderScheduler::class);
        $result = $scheduler->schedule($championship, minimumRest: 1);

        expect($result['scheduled'])->toBe(10);

        $ordered = $championship->bouts()->whereNotNull('fight_number')->orderBy('fight_number')->get();

        // Numbered in the draw's own order: every round complete before the
        // next, and each round in the order the circle produced it.
        $sequence = $ordered->map(fn (Bout $bout) => [$bout->round, $bout->position_in_round])->all();

        expect($sequence)->toBe(collect($sequence)->sort()->values()->all());

        // Whoever that brings back to the mat too soon is reported.
        $backToBack = [];

        foreach ($ordered as $index => $bout) {
            $previous = $ordered[$index - 1] ?? null;

            if ($previous !== null && array_intersect(
                [$previous->athlete_a_id, $previous->athlete_b_id],
                [$bout->athlete_a_id, $bout->athlete_b_id],
            ) !== []) {
                $backToBack[] = $bout->fight_number;
            }
        }

        expect($backToBack)->not->toBeEmpty()
            ->and($result['violations'])->toBeGreaterThanOrEqual(count($backToBack))
            ->and($scheduler->restViolations($championship, 1)->pluck('bout.fight_number')->all())
            ->toContain(...$backToBack);
    });

    /**
     * Four athletes fighting three contests each inside a session of six
     * cannot be rested three bouts apart by any order at all. Saying so is
     * more use than a list of violations that reads like a mistake.
     */
    it('reports a rest that no order could have delivered', function () {
        $category = drawnRoundRobin(4, '-impossible');
        $championship = $category->ageCategory->championship;

        $result = app(FightOrderScheduler::class)->schedule($championship, minimumRest: 3);

        expect($result['unattainable'])->toBeGreaterThan(0);

        $report = app(FightOrderScheduler::class)->unattainableRest($championship, 3);

        expect($report->first()['contests'])->toBe(3)
            ->and($report->first()['total'])->toBe(6)
            // floor((6-1)/(3-1)) = 2, and 3 was asked for.
            ->and($report->first()['best_possible'])->toBe(2)
            ->and($report->first()['requested'])->toBe(3);
    });

    /** The knockout's feeder rule is untouched. */
    it('still numbers a bracket after the bouts that feed it', function () {
        [$category] = categoryWithAthletes(8, '-feeders');
        app(DrawGenerator::class)->generate($category);

        $championship = $category->ageCategory->championship;
        app(FightOrderScheduler::class)->schedule($championship);

        foreach ($championship->bouts()->whereNotNull('next_bout_id')->get() as $feeder) {
            $next = Bout::find($feeder->next_bout_id);

            if ($feeder->fight_number && $next?->fight_number) {
                expect($next->fight_number)->toBeGreaterThan($feeder->fight_number);
            }
        }
    });
});

describe('an upgrade over a competition already under way', function () {
    /**
     * The backfill, run against real rows rather than the empty schema a fresh
     * migration sees. Everything already drawn is a bracket, and must stay one
     * however small the class is.
     */
    it('stamps every existing draw as a knockout', function () {
        [$small] = categoryWithAthletes(3, '-legacy-small');
        [$large] = categoryWithAthletes(8, '-legacy-large');
        [$undrawn] = categoryWithAthletes(4, '-legacy-undrawn');

        app(BracketGenerator::class)->generate($small);
        app(BracketGenerator::class)->generate($large);

        // As rows written before the column existed would look.
        WeightCategory::whereIn('id', [$small->id, $large->id, $undrawn->id])
            ->update(['draw_format' => null, 'draw_format_preference' => null]);

        $migration = require database_path(
            'migrations/2026_08_24_090000_add_tournament_format_to_weight_categories_table.php'
        );

        $stamped = $migration->backfill();

        expect($stamped)->toBe(2)
            ->and($small->refresh()->draw_format)->toBe('knockout')
            ->and($large->refresh()->draw_format)->toBe('knockout')
            // A class nobody has drawn is not given a format it never had.
            ->and($undrawn->refresh()->draw_format)->toBeNull()
            // And no preference is invented on anybody's behalf.
            ->and($small->draw_format_preference)->toBeNull();
    });

    /** A stamped legacy bracket keeps its bracket everywhere. */
    it('goes on rendering a small legacy class as the bracket it is', function () {
        [$category] = categoryWithAthletes(3, '-legacy-render');
        app(BracketGenerator::class)->generate($category);

        WeightCategory::whereKey($category->id)->update(['draw_format' => 'knockout']);
        $category->refresh();

        expect($category->isRoundRobin())->toBeFalse()
            ->and($category->drawFormat())->toBe(TournamentFormat::Knockout);

        $this->actingAs($this->official)
            ->get(route('display.bracket', $category))
            ->assertOk()
            ->assertSee('bkt__slot', false);

        $html = Livewire::actingAs($this->admin)
            ->test(BracketScreen::class, ['weightCategory' => $category])->html();

        expect($html)->toContain('bkt__slot');
    });
});

describe('every field size from one to sixteen', function () {
    /**
     * The regression sweep. Format resolution sits in front of both
     * generators, so every size the federation runs is drawn end to end and
     * checked against what its own format promises.
     */
    it('draws whatever the rule gives it, and nothing else', function (int $athletes) {
        [$category] = categoryWithAthletes($athletes, '-sweep');

        app(DrawGenerator::class)->generate($category);
        $category->refresh();

        $format = $category->drawFormat();
        $bouts = $category->bouts()->get();

        expect($category->hasDraw())->toBeTrue();

        if ($athletes === 1) {
            expect($format)->toBe(TournamentFormat::Placement)
                ->and($bouts)->toBeEmpty();

            return;
        }

        if ($athletes <= 5) {
            expect($format)->toBe(TournamentFormat::RoundRobin)
                ->and($bouts)->toHaveCount(RoundRobinGenerator::contestsFor($athletes))
                ->and($bouts->whereNotNull('next_bout_id'))->toBeEmpty()
                ->and($bouts->where('is_bye', true))->toBeEmpty();

            return;
        }

        $size = BracketSeeding::size($athletes);

        expect($format)->toBe(TournamentFormat::Knockout)
            ->and($bouts)->toHaveCount($size - 1)
            ->and($bouts->whereNull('next_bout_id'))->toHaveCount(1)
            ->and($category->draw_bucket_size)->toBe($size);
    })->with(range(1, 16));
});
