<?php

/**
 * Who the rules let into a draw.
 *
 * The IKA rule is one sentence: an athlete who has not been weighed must not be
 * admitted to competition. The interesting part is that "has not been weighed"
 * covers two states, and the code used to test for only one of them — an
 * athlete who failed the scale was kept out, and an athlete nobody had put on
 * the scale at all was let straight through.
 *
 * These tests run the rule at every gate it is enforced at, because the gates
 * are minutes or hours apart in real use and a weigh-in desk runs between them:
 * handing out numbers, generating the draw, and publishing it.
 *
 * The other half of the file is about what happens when a pass is taken back.
 * That is not the sport's rule but this application's decision, and it is
 * stated here so it cannot drift: before a draw exists the number is released,
 * and once a draw is built around somebody the scale is refused until the draw
 * is deleted.
 */

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\WeighIn;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BracketGenerator;
use App\Services\DrawEligibility;
use App\Services\DrawEligibilityException;
use App\Services\DrawGenerator;
use App\Services\RoundRobinGenerator;
use App\Support\TournamentFormat;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->admin);
});

/**
 * A class whose athletes are in whatever weigh-in states the test needs.
 *
 * Statuses are given as a list, one per athlete, so a test reads as the
 * situation it is about: ['pass', 'pass', 'pending'] is a class of three with
 * one who has not been to the scale.
 *
 * @param  list<string>  $statuses
 * @return array{0: WeightCategory, 1: Collection<int, Athlete>}
 */
function classOfStatuses(array $statuses, bool $numbered = false, string $label = '-66'): array
{
    // No serial on the label: every call makes its own age category, and the
    // unique constraint is (age_category_id, label). A suffixed label would
    // also stop being a weight a validator can read.
    $ageCategory = AgeCategory::factory()->create();

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => $label,
    ]);

    $athletes = collect($statuses)->values()->map(function (string $status, int $index) use ($ageCategory, $category, $numbered) {
        $factory = Athlete::factory();

        if ($numbered) {
            $factory = $factory->drawn($index + 1);
        }

        return $factory->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'fullname' => 'Eligibility Athlete '.($index + 1),
            'weighin_status' => $status,
            // Inside the factory's 60-66 band for a pass, so a class built
            // here is one the scale would actually have admitted.
            'weighin_kg' => $status === Athlete::WEIGHIN_PENDING ? null : 63,
        ]);
    });

    return [$category->refresh(), $athletes];
}

describe('the central definition', function () {
    it('admits only an athlete who passed the scale', function (string $status, bool $admitted) {
        [$category] = classOfStatuses([$status], numbered: true);

        expect($category->eligibleAthletes()->count())->toBe($admitted ? 1 : 0)
            ->and($category->drawnAthletes()->count())->toBe($admitted ? 1 : 0)
            // The number is still on the row either way — the rule is about
            // admission, not about tidying the athletes table.
            ->and($category->numberedAthletes()->count())->toBe(1)
            ->and($category->ineligibleNumberedAthletes()->count())->toBe($admitted ? 0 : 1);
    })->with([
        'passed' => ['pass', true],
        'not weighed' => ['pending', false],
        'failed' => ['fail', false],
    ]);

    /** The bug this replaces: "not fail" is not the same question as "passed". */
    it('does not treat an unweighed athlete as merely not-failed', function () {
        [$category] = classOfStatuses(['pass', 'pending', 'fail'], numbered: true);

        expect($category->drawnAthletes()->pluck('weighin_status')->all())->toBe(['pass'])
            ->and($category->athletes()->where('weighin_status', '!=', 'fail')->count())->toBe(2);
    });
});

describe('the random draw', function () {
    it('gives numbers to the athletes who passed and to nobody else', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pending', 'fail', 'pass']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])->call('drawAtRandom');

        $numbered = $category->refresh()->numberedAthletes()->get();

        expect($numbered)->toHaveCount(2)
            ->and($numbered->pluck('weighin_status')->unique()->all())->toBe(['pass'])
            // Consecutive from one, so the field the generator seats is whole.
            ->and($numbered->pluck('draw_number')->map(fn ($n) => (int) $n)->all())->toBe([1, 2]);

        foreach ([1, 2] as $index) {
            expect($athletes[$index]->refresh()->draw_number)->toBeNull();
        }
    });

    it('takes back a number from somebody who is no longer eligible', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass'], numbered: true);

        // Failed after being numbered — by a route that does not clear it, the
        // way a legacy import or a direct correction would.
        $athletes[1]->forceFill(['weighin_status' => Athlete::WEIGHIN_FAIL])->save();

        Livewire::test(Bracket::class, ['weightCategory' => $category])->call('drawAtRandom');

        expect($athletes[1]->refresh()->draw_number)->toBeNull()
            ->and($category->refresh()->numberedAthletes()->count())->toBe(1);
    });

    it('refuses a class in which nobody has passed', function () {
        [$category] = classOfStatuses(['pending', 'fail']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('drawAtRandom')
            ->assertDispatched('draw-failed');

        expect($category->refresh()->numberedAthletes()->count())->toBe(0);
    });

    /** A class nobody has weighed at all is the commonest form of that. */
    it('refuses a class nobody has been to the scale for', function () {
        [$category] = classOfStatuses(['pending', 'pending', 'pending']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('drawAtRandom')
            ->assertDispatched('draw-failed');

        expect($category->refresh()->numberedAthletes()->count())->toBe(0);
    });
});

describe('manual draw numbers', function () {
    it('refuses a number for somebody who has not been weighed', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pending']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set('draws', [$athletes[0]->id => '1', $athletes[1]->id => '2'])
            ->call('saveDraws')
            // Asserted on what the operator sees: Livewire's harness ages the
            // flash before session('error') can be read back.
            ->assertSee('Eligibility Athlete 2')
            ->assertSee('not weighed');
    });

    it('refuses a number for somebody who failed', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'fail']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set('draws', [$athletes[0]->id => '1', $athletes[1]->id => '2'])
            ->call('saveDraws')
            ->assertSee('Eligibility Athlete 2')
            ->assertSee('failed weigh-in');
    });

    /**
     * All of it or none of it. A screen half-saved around the one row that was
     * wrong is a draw nobody can reason about.
     */
    it('saves nothing at all when one athlete in the list is ineligible', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass', 'pending']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set('draws', [
                $athletes[0]->id => '1',
                $athletes[1]->id => '2',
                $athletes[2]->id => '3',
            ])
            ->call('saveDraws');

        expect($category->refresh()->numberedAthletes()->count())->toBe(0)
            ->and($athletes[0]->refresh()->draw_number)->toBeNull()
            ->and($athletes[1]->refresh()->draw_number)->toBeNull();
    });

    /** The entered numbers survive the refusal, so nothing has to be retyped. */
    it('keeps what was entered when it refuses', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pending']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set('draws', [$athletes[0]->id => '7', $athletes[1]->id => '8'])
            ->call('saveDraws')
            ->assertSet('draws.'.$athletes[0]->id, '7')
            ->assertSet('draws.'.$athletes[1]->id, '8');
    });

    it('saves a list in which everybody has passed', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set('draws', [$athletes[0]->id => '2', $athletes[1]->id => '1'])
            ->call('saveDraws');

        expect($athletes[0]->refresh()->draw_number)->toBe(2)
            ->and($athletes[1]->refresh()->draw_number)->toBe(1);
    });

    /**
     * The keys of $draws are whatever the browser posted. An id from another
     * class must not reach an update.
     */
    it('refuses an athlete who belongs to another weight class', function () {
        [$category, $athletes] = classOfStatuses(['pass']);
        [$other, $strangers] = classOfStatuses(['pass'], label: '-81');

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set('draws', [$athletes[0]->id => '1', $strangers[0]->id => '2'])
            ->call('saveDraws')
            ->assertSee('not in this weight class');

        expect($strangers[0]->refresh()->draw_number)->toBeNull()
            ->and($athletes[0]->refresh()->draw_number)->toBeNull();
    });
});

describe('generating the draw', function () {
    it('refuses a bracket whose field contains somebody unweighed', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 8, 'pass'), numbered: true);

        $athletes[3]->forceFill(['weighin_status' => Athlete::WEIGHIN_PENDING])->save();

        expect(fn () => app(DrawGenerator::class)->generate($category->refresh()))
            ->toThrow(DrawEligibilityException::class);

        expect($category->refresh()->bouts()->count())->toBe(0);
    });

    it('refuses a round robin whose field contains somebody who failed', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass', 'pass'], numbered: true);

        $athletes[2]->forceFill(['weighin_status' => Athlete::WEIGHIN_FAIL])->save();

        expect(fn () => app(DrawGenerator::class)->generate($category->refresh()))
            ->toThrow(DrawEligibilityException::class);

        expect($category->refresh()->bouts()->count())->toBe(0);
    });

    it('refuses to place a sole entrant who has not passed', function () {
        [$category, $athletes] = classOfStatuses(['pass'], numbered: true);

        app(DrawGenerator::class)->generate($category);

        expect($category->refresh()->drawFormat())->toBe(TournamentFormat::Placement);

        $athletes[0]->forceFill(['weighin_status' => Athlete::WEIGHIN_PENDING])->save();

        expect(fn () => app(DrawGenerator::class)->placeSoleAthlete($category->refresh(), $this->admin))
            ->toThrow(DrawEligibilityException::class);

        expect($category->refresh()->draw_placement_athlete_id)->toBeNull();
    });

    it('names the athlete it refused over', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass', 'pass', 'pass'], numbered: true);

        $athletes[1]->forceFill(['weighin_status' => Athlete::WEIGHIN_PENDING])->save();

        try {
            app(DrawGenerator::class)->generate($category->refresh());
            $this->fail('The draw should have been refused.');
        } catch (DrawEligibilityException $e) {
            expect($e->getMessage())->toContain('Eligibility Athlete 2')
                ->and($e->getMessage())->toContain('not weighed');
        }
    });

    it('surfaces the refusal on the draw screen rather than throwing at it', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass', 'pass', 'pass'], numbered: true);

        $athletes[0]->forceFill(['weighin_status' => Athlete::WEIGHIN_FAIL])->save();

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->call('generate')
            ->assertDispatched('draw-failed');

        expect($category->refresh()->bouts()->count())->toBe(0);
    });

    /**
     * The generators are reachable without going through DrawGenerator — a
     * command, a test, a screen written later — so each keeps the guard itself
     * rather than trusting the door it is usually opened by.
     */
    it('refuses a bracket asked for directly, rather than seating a smaller one', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 8, 'pass'), numbered: true);

        $athletes[5]->forceFill(['weighin_status' => Athlete::WEIGHIN_PENDING])->save();

        expect(fn () => app(BracketGenerator::class)->generate($category->refresh()))
            ->toThrow(DrawEligibilityException::class);

        expect($category->refresh()->bouts()->count())->toBe(0);
    });

    it('refuses a round robin asked for directly', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass', 'pass', 'pass'], numbered: true);

        $athletes[0]->forceFill(['weighin_status' => Athlete::WEIGHIN_FAIL])->save();

        expect(fn () => app(RoundRobinGenerator::class)->generate($category->refresh()))
            ->toThrow(DrawEligibilityException::class);

        expect($category->refresh()->bouts()->count())->toBe(0);
    });

    it('draws normally when the whole field has passed', function () {
        [$category] = classOfStatuses(array_fill(0, 8, 'pass'), numbered: true);

        $result = app(DrawGenerator::class)->generate($category);

        expect($result['athletes'])->toBe(8)
            ->and($category->refresh()->bouts()->count())->toBe(7);
    });

    /** The count that picks the format is the eligible one, not the numbered one. */
    it('counts only eligible athletes when choosing the format', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 6, 'pass'), numbered: true);

        // Two fall out, leaving four — which the IKA rule runs as a round
        // robin. Their numbers are released the way the weigh-in desk would.
        foreach ([4, 5] as $index) {
            $athletes[$index]->forceFill([
                'weighin_status' => Athlete::WEIGHIN_FAIL,
                'draw_number' => null,
                'draw_number_source' => null,
            ])->save();
        }

        $result = app(DrawGenerator::class)->generate($category->refresh());

        expect($result['format'])->toBe(TournamentFormat::RoundRobin)
            ->and($result['athletes'])->toBe(4);
    });
});

describe('publishing the draw', function () {
    it('refuses to publish when somebody in the draw has lost their pass', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        app(DrawGenerator::class)->generate($category);

        $athletes[2]->forceFill(['weighin_status' => Athlete::WEIGHIN_FAIL])->save();

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->call('publishDraw')
            ->assertSee('Eligibility Athlete 3');

        expect($category->refresh()->isDrawPublished())->toBeFalse();
    });

    /**
     * Clearing the number does not take somebody out of a bracket that was
     * built around them, so the check reads the contests.
     */
    it('finds an athlete in the bouts even when their draw number was cleared', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        app(DrawGenerator::class)->generate($category);

        $athletes[1]->forceFill([
            'weighin_status' => Athlete::WEIGHIN_PENDING,
            'draw_number' => null,
            'draw_number_source' => null,
        ])->save();

        expect($category->refresh()->ineligibleNumberedAthletes()->count())->toBe(0)
            ->and(app(DrawEligibility::class)->ineligibleInGeneratedDraw($category))->toHaveCount(1);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('publishDraw');

        expect($category->refresh()->isDrawPublished())->toBeFalse();
    });

    it('publishes a draw whose field is intact', function () {
        [$category] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        app(DrawGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->call('publishDraw');

        expect($category->refresh()->isDrawPublished())->toBeTrue();
    });

    it('refuses to publish a placement whose athlete lost their pass', function () {
        [$category, $athletes] = classOfStatuses(['pass'], numbered: true);

        app(DrawGenerator::class)->generate($category);
        app(DrawGenerator::class)->placeSoleAthlete($category->refresh(), $this->admin);

        $athletes[0]->forceFill(['weighin_status' => Athlete::WEIGHIN_FAIL])->save();

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->call('publishDraw');

        expect($category->refresh()->isDrawPublished())->toBeFalse();
    });
});

describe('losing a pass at the scale', function () {
    /**
     * Before a draw exists, the number is a plan rather than a document. It
     * goes when the pass that justified it goes.
     */
    it('releases the draw number when nothing has been generated yet', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pass'], numbered: true);

        $athlete = $athletes[0];
        $championship = $category->ageCategory->championship;

        Livewire::test(WeighIn::class, [
            'championship' => $championship,
            'competition' => $category->ageCategory->gender,
        ])
            // Far outside any class, so the verdict is a failure.
            ->set('weights', [$athlete->id => '250'])
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_status)->toBe(Athlete::WEIGHIN_FAIL)
            ->and($athlete->draw_number)->toBeNull()
            ->and($athlete->draw_number_source)->toBeNull();
    });

    /**
     * Once a draw is built around somebody, the scale is refused rather than
     * the bracket being rewritten underneath whoever is holding it. This is
     * this application's decision and not a rule of the sport — see the note in
     * WeighIn::record().
     */
    it('refuses the weight and keeps the draw intact once one is generated', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        app(DrawGenerator::class)->generate($category);

        $athlete = $athletes[0];
        $before = $category->refresh()->bouts()->count();

        Livewire::test(WeighIn::class, [
            'championship' => $category->ageCategory->championship,
            'competition' => $category->ageCategory->gender,
        ])
            ->set('weights', [$athlete->id => '250'])
            ->call('record', $athlete->id)
            ->assertSee('is in the generated draw');

        // Nothing moved: not the status, not the number, not the contests.
        expect($athlete->refresh()->weighin_status)->toBe(Athlete::WEIGHIN_PASS)
            ->and($athlete->draw_number)->not->toBeNull()
            ->and($category->refresh()->bouts()->count())->toBe($before);
    });

    /** Somebody in a drawn class who was never in the draw can still be weighed. */
    it('still weighs an athlete the existing draw does not contain', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        app(DrawGenerator::class)->generate($category);

        // A late entry: in the class, in no contest.
        $late = Athlete::factory()->create([
            'championship_id' => $category->ageCategory->championship_id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
            'fullname' => 'Late Arrival',
            'weighin_status' => Athlete::WEIGHIN_PENDING,
        ]);

        Livewire::test(WeighIn::class, [
            'championship' => $category->ageCategory->championship,
            'competition' => $category->ageCategory->gender,
        ])
            ->set('weights', [$late->id => '250'])
            ->call('record', $late->id);

        expect($late->refresh()->weighin_status)->toBe(Athlete::WEIGHIN_FAIL);
    });

    it('leaves a passing athlete alone', function () {
        [$category, $athletes] = classOfStatuses(['pass'], numbered: true);

        $athlete = $athletes[0];

        Livewire::test(WeighIn::class, [
            'championship' => $category->ageCategory->championship,
            'competition' => $category->ageCategory->gender,
        ])
            // Inside the class, so the verdict is a pass and nothing changes.
            ->set('weights', [$athlete->id => '63'])
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_status)->toBe(Athlete::WEIGHIN_PASS)
            ->and($athlete->draw_number)->toBe(1);
    });
});

describe('a draw that already exists', function () {
    /**
     * Legacy rows: a championship imported from the old database can hold a
     * numbered athlete who never passed. The screens must say so rather than
     * dropping them, and the sheets must keep rendering the draw that was
     * actually made.
     */
    it('keeps rendering a historical draw that contains an ineligible athlete', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        app(DrawGenerator::class)->generate($category);

        // Written the way an import would: straight onto the row.
        Athlete::whereKey($athletes[2]->id)->update(['weighin_status' => Athlete::WEIGHIN_PENDING]);

        $category->refresh();

        expect($category->numberedAthletes()->count())->toBe(4)
            ->and($category->drawnAthletes()->count())->toBe(3)
            ->and($category->ineligibleNumberedAthletes()->count())->toBe(1)
            // The contests are untouched: four athletes is a round robin, so
            // six of them, and every athlete is still in one.
            ->and($category->bouts()->count())->toBe(6);
    });

    it('warns on the draw screen instead of hiding the athlete', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        Athlete::whereKey($athletes[1]->id)->update(['weighin_status' => Athlete::WEIGHIN_FAIL]);

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->assertSee('Eligibility Athlete 2')
            ->assertSee('have not passed the weigh-in');
    });

    it('leaves an archived championship readable', function () {
        [$category, $athletes] = classOfStatuses(array_fill(0, 4, 'pass'), numbered: true);

        app(DrawGenerator::class)->generate($category);

        Athlete::whereKey($athletes[0]->id)->update(['weighin_status' => Athlete::WEIGHIN_PENDING]);

        Championship::whereKey($category->ageCategory->championship_id)->update(['archived_at' => now()]);

        $category->refresh();

        expect($category->bouts()->count())->toBe(6)
            ->and($category->numberedAthletes()->count())->toBe(4)
            ->and($category->isDrawPublished())->toBeFalse();
    });
});

describe('the draw screen', function () {
    it('closes the number field for an athlete who has not passed', function () {
        [$category, $athletes] = classOfStatuses(['pass', 'pending', 'fail']);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category])->html();

        expect($html)->toContain('Not weighed yet')
            ->and($html)->toContain('Weighed outside this class')
            ->and(substr_count($html, 'disabled'))->toBeGreaterThanOrEqual(2);
    });

    it('leaves the field open for an athlete who passed', function () {
        [$category] = classOfStatuses(['pass', 'pass']);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category])->html();

        expect($html)->not->toContain('Not weighed yet')
            ->and($html)->not->toContain('Weighed outside this class');
    });
});
