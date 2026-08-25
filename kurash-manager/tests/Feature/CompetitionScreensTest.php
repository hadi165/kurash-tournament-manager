<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Categories;
use App\Livewire\Competition\Championships;
use App\Livewire\Competition\Medals;
use App\Livewire\Competition\Registration;
use App\Livewire\Competition\WeighIn;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BracketGenerator;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

describe('access', function () {
    it('sends guests to the login page', function (string $route) {
        $championship = Championship::factory()->create();
        $ageCategory = AgeCategory::factory()->create(['championship_id' => $championship->id]);
        $weightCategory = WeightCategory::factory()->create(['age_category_id' => $ageCategory->id]);

        $url = match ($route) {
            'championships.index' => route('championships.index'),
            'championships.show' => route('championships.show', $championship),
            'medals.index' => route('medals.index', $championship),
            'athletes.index' => route('athletes.index', ['championship' => $championship, 'competition' => 'M']),
            'weighin.index' => route('weighin.index', ['championship' => $championship, 'competition' => 'M']),
            'bracket.show' => route('bracket.show', $weightCategory),
        };

        $this->get($url)->assertRedirect(route('login'));
    })->with([
        'championships.index', 'championships.show', 'medals.index',
        'athletes.index', 'weighin.index', 'bracket.show',
    ]);

    it('lets a signed-in user read every screen', function () {
        $championship = Championship::factory()->create();
        $ageCategory = AgeCategory::factory()->create(['championship_id' => $championship->id]);
        $weightCategory = WeightCategory::factory()->create(['age_category_id' => $ageCategory->id]);

        $this->actingAs($this->viewer);

        $this->get(route('championships.index'))->assertOk();
        $this->get(route('championships.show', $championship))->assertOk();
        $this->get(route('medals.index', $championship))->assertOk();
        $this->get(route('athletes.index', ['championship' => $championship, 'competition' => 'M']))->assertOk();
        $this->get(route('weighin.index', ['championship' => $championship, 'competition' => 'M']))->assertOk();
    });

    /**
     * The one screen a reader does not get.
     *
     * The bracket screen is where a draw is made, so it shows pairings before
     * anybody has approved them. Reading a draw now goes through the published
     * table instead, which is what an operator presents from.
     */
    it('keeps the working bracket screen for the people who run the draw', function () {
        $championship = Championship::factory()->create();
        $ageCategory = AgeCategory::factory()->create(['championship_id' => $championship->id]);
        $weightCategory = WeightCategory::factory()->create(['age_category_id' => $ageCategory->id]);

        $this->actingAs($this->viewer)->get(route('bracket.show', $weightCategory))->assertForbidden();
        $this->actingAs($this->admin)->get(route('bracket.show', $weightCategory))->assertOk();
    });

    /**
     * The original kurash-access-guard.php existed but no file included it, so
     * every account had full access. These assert the gate is actually wired.
     */
    it('stops a viewer from changing competition data', function () {
        $this->actingAs($this->viewer);

        Livewire::test(Championships::class)
            ->set('title', 'Should not be created')
            ->call('save')
            ->assertForbidden();

        expect(Championship::count())->toBe(0);
    });

    it('stops a viewer from recording a weigh-in', function () {
        [$category] = categoryWithAthletes(4);
        $athlete = $category->athletes()->first();

        $this->actingAs($this->viewer);

        Livewire::test(WeighIn::class, [
            'championship' => $category->ageCategory->championship,
            'competition' => $category->ageCategory->gender,
        ])
            ->set("weights.{$athlete->id}", '64.8')
            ->call('record', $athlete->id)
            ->assertForbidden();

        expect($athlete->refresh()->weighin_kg)->toBeNull();
    });

    it('stops a viewer from drawing a bracket', function () {
        [$category] = categoryWithAthletes(4);

        $this->actingAs($this->viewer);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate')
            ->assertForbidden();

        expect($category->bouts()->count())->toBe(0);
    });
});

describe('championships', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('creates one', function () {
        Livewire::test(Championships::class)
            ->set('title', 'Asian Kurash Championship 2026')
            ->set('location', 'Tashkent')
            ->call('save')
            ->assertHasNoErrors();

        expect(Championship::where('title', 'Asian Kurash Championship 2026')->exists())->toBeTrue();
    });

    it('requires a title', function () {
        Livewire::test(Championships::class)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);
    });

    it('refuses to delete one that has athletes', function () {
        [$category] = categoryWithAthletes(2);
        $championship = $category->ageCategory->championship;

        Livewire::test(Championships::class)->call('delete', $championship->id);

        expect(Championship::find($championship->id))->not->toBeNull();
    });

    it('deletes an empty one', function () {
        $championship = Championship::factory()->create();

        Livewire::test(Championships::class)->call('delete', $championship->id);

        expect(Championship::find($championship->id))->toBeNull();
    });
});

describe('categories', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('turns a comma-separated list into weight category rows', function () {
        $championship = Championship::factory()->create();

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('ageGroup', 'Senior')
            ->set('weightLabels', '-60, -66, -73, +90')
            ->set('gender', 'M')
            ->call('save')
            ->assertHasNoErrors();

        $ageCategory = $championship->ageCategories()->first();
        $labels = $ageCategory->weightCategories()->orderBy('sort_order')->pluck('label')->all();

        expect($labels)->toBe(['-60', '-66', '-73', '+90']);
    });

    it('reads bounds out of the label', function () {
        $championship = Championship::factory()->create();

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('ageGroup', 'Senior')
            ->set('weightLabels', '-66, +90')
            ->call('save');

        $categories = $championship->ageCategories()->first()->weightCategories()->orderBy('sort_order')->get();

        expect((float) $categories[0]->max_kg)->toBe(66.0)
            ->and($categories[0]->min_kg)->toBeNull()
            ->and((float) $categories[1]->min_kg)->toBe(90.0)
            ->and($categories[1]->max_kg)->toBeNull();
    });

    it('rejects an empty weight list', function () {
        $championship = Championship::factory()->create();

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('ageGroup', 'Senior')
            ->set('weightLabels', ' , , ')
            ->call('save')
            ->assertHasErrors('weightLabels');
    });

    it('keeps a weight class that still has athletes in it', function () {
        [$category] = categoryWithAthletes(3);
        $ageCategory = $category->ageCategory;

        Livewire::test(Categories::class, ['championship' => $ageCategory->championship])
            ->call('edit', $ageCategory->id)
            ->set('weightLabels', '-73')       // tries to drop -66, which has athletes
            ->call('save');

        expect(WeightCategory::find($category->id))->not->toBeNull();
    });
});

describe('registration', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('registers an athlete and issues an IKA ID', function () {
        [$category] = categoryWithAthletes(0);

        Livewire::test(Registration::class, [
            'championship' => $category->ageCategory->championship,
            'competition' => 'M',
        ])
            ->set('fullname', 'Ghader Nasb')
            ->set('noc_code', 'afg')
            ->set('date_of_birth', dobFor())
            ->set('gender', 'M')
            ->set('weight_category_id', $category->id)
            ->call('save')
            ->assertHasNoErrors();

        $athlete = Athlete::where('fullname', 'Ghader Nasb')->first();

        expect($athlete)->not->toBeNull()
            ->and($athlete->noc_code)->toBe('AFG')          // upper-cased
            // Three digits, counted within the championship: the first athlete
            // of every event is IKA001.
            ->and($athlete->ika_id)->toBe('IKA001');
    });

    /**
     * Guards against the IDOR in the old registration page, whose UPDATE was
     * scoped by row id alone.
     */
    it('refuses a weight class from another championship', function () {
        [$mine] = categoryWithAthletes(0);
        [$theirs] = categoryWithAthletes(0, '-73');

        Livewire::test(Registration::class, [
            'championship' => $mine->ageCategory->championship,
            'competition' => 'M',
        ])
            ->set('fullname', 'Intruder')
            ->set('noc_code', 'UZB')
            ->set('date_of_birth', dobFor())
            ->set('weight_category_id', $theirs->id)
            ->call('save')
            ->assertHasErrors('weight_category_id');

        expect(Athlete::where('fullname', 'Intruder')->exists())->toBeFalse();
    });

    it('clears the draw number when an athlete changes weight class', function () {
        $ageCategory = AgeCategory::factory()->create();
        $from = WeightCategory::factory()->create(['age_category_id' => $ageCategory->id, 'label' => '-66']);
        $to = WeightCategory::factory()->create(['age_category_id' => $ageCategory->id, 'label' => '-73']);

        $athlete = Athlete::factory()->drawn(3)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $from->id,
        ]);

        Livewire::test(Registration::class, [
            'championship' => $ageCategory->championship,
            'competition' => 'M',
        ])
            ->call('edit', $athlete->id)
            ->set('weight_category_id', $to->id)
            ->call('save');

        expect($athlete->refresh()->weight_category_id)->toBe($to->id)
            ->and($athlete->draw_number)->toBeNull();
    });
});

describe('weigh-in', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    /**
     * The class runs from its floor to its ceiling, with 500 grams of grace
     * below the floor — not a 500-gram window below the ceiling, which is what
     * the rule used to be and which rejected most of the class. The bands
     * themselves are covered in detail in WeightValidationTest.
     */
    it('passes a weight inside the class and fails one outside', function (float $kg, string $expected) {
        [$category] = categoryWithAthletes(1);   // -66 class, floor 60, limit 66
        $athlete = $category->athletes()->first();

        Livewire::test(WeighIn::class, [
            'championship' => $category->ageCategory->championship,
            'competition' => $category->ageCategory->gender,
        ])
            ->set("weights.{$athlete->id}", (string) $kg)
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_status)->toBe($expected)
            ->and((float) $athlete->weighin_kg)->toBe($kg);
    })->with([
        'at the limit' => [66.0, 'pass'],
        'just under the limit' => [65.6, 'pass'],
        'well inside the class' => [64.9, 'pass'],
        // The 500 grams the federation allows over a minus class.
        'inside the tolerance above the limit' => [66.4, 'pass'],
        'at the top of the tolerance' => [66.5, 'pass'],
        'over the tolerance' => [66.6, 'fail'],
        'inside the tolerance below the floor' => [59.6, 'pass'],
        'below the tolerance' => [59.4, 'fail'],
    ]);

    it('rejects a non-numeric entry without touching the record', function () {
        [$category] = categoryWithAthletes(1);
        $athlete = $category->athletes()->first();

        Livewire::test(WeighIn::class, [
            'championship' => $category->ageCategory->championship,
            'competition' => $category->ageCategory->gender,
        ])
            ->set("weights.{$athlete->id}", 'heavy')
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_kg)->toBeNull();
    });
});

describe('bracket screen', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    /**
     * A screen somebody works in for a whole division needs a way out of it
     * that is not a breadcrumb: the class list is where every one of these is
     * opened from, and going back to it is the commonest thing anybody does
     * here.
     */
    it('offers a way back to the weight classes of its own competition', function (string $gender, string $label) {
        [$category] = categoryWithAthletes(4, '-back-'.$gender);
        $category->ageCategory->forceFill(['gender' => $gender])->save();

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])->html();

        $expected = route('entries.index', [
            'championship' => $category->ageCategory->championship,
            'competition' => $gender,
        ]);

        // The entry list, narrowed the way the official was working: whoever
        // came in from the women's classes goes back to the women's classes.
        expect($html)->toContain(e($expected))
            ->and($html)->toContain(__('All :competition weight classes', ['competition' => $label]));
    })->with([
        'men' => ['M', 'Men'],
        'women' => ['F', 'Women'],
    ]);

    it('draws a bracket and records a result through to the podium', function () {
        // Eight, because that is a field the IKA rule draws as a bracket. A
        // smaller one is a round robin now and would be testing the other
        // format — see TournamentFormatTest.
        [$category, $athletes] = categoryWithAthletes(8);

        $component = Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate');

        expect($category->bouts()->count())->toBe(7);

        // Lower draw number wins every bout.
        foreach ([1, 2, 3] as $round) {
            foreach ($category->bouts()->where('round', $round)->get() as $bout) {
                $bout->refresh();

                if (! $bout->isReadyToFight()) {
                    continue;
                }

                $side = $bout->athleteA->draw_number < $bout->athleteB->draw_number ? 'a' : 'b';
                $component->call('recordResult', $bout->id, $side);
            }
        }

        $component->assertOk();

        expect($category->bouts()->whereNull('winner_athlete_id')->count())->toBe(0);

        $final = $category->bouts()->whereNull('next_bout_id')->first();
        expect($final->winner_athlete_id)->toBe($athletes[1]->id);
    });

    it('refuses to draw with fewer than two drawn athletes', function () {
        [$category] = categoryWithAthletes(1);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate');

        expect($category->bouts()->count())->toBe(0);
    });

    it('rejects duplicate draw numbers before saving any of them', function () {
        [$category, $athletes] = categoryWithAthletes(3);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set("draws.{$athletes[1]->id}", '1')
            ->set("draws.{$athletes[2]->id}", '1')
            ->call('saveDraws');

        // Nothing changed — the originals are still 1, 2, 3.
        expect($category->athletes()->pluck('draw_number')->sort()->values()->all())->toBe([1, 2, 3]);
    });

    describe('deleting the bracket', function () {
        it('throws the drawn bracket away and keeps the draw numbers', function () {
            [$category, $athletes] = categoryWithAthletes(4);
            app(BracketGenerator::class)->generate($category);

            Livewire::test(Bracket::class, ['weightCategory' => $category])
                ->call('deleteBracket');

            expect($category->bouts()->count())->toBe(0)
                ->and($athletes[1]->refresh()->draw_number)->toBe(1);
        });

        /** Which is the whole point: registration refuses while a bracket stands. */
        it('lets an athlete be removed afterwards', function () {
            [$category] = categoryWithAthletes(4);
            app(BracketGenerator::class)->generate($category);

            Livewire::test(Bracket::class, ['weightCategory' => $category])
                ->call('deleteBracket');

            $ageCategory = $category->ageCategory;
            $athlete = $category->athletes()->firstOrFail();

            Livewire::test(Registration::class, [
                'championship' => $ageCategory->championship,
                'competition' => 'M',
            ])
                ->call('delete', $athlete->id);

            expect(Athlete::find($athlete->id))->toBeNull();
        });

        it('asks again before erasing a decided contest', function () {
            [$category] = categoryWithAthletes(4);
            app(BracketGenerator::class)->generate($category);
            runTournament($category);

            $component = Livewire::test(Bracket::class, ['weightCategory' => $category])
                ->call('deleteBracket');

            expect($category->bouts()->count())->toBeGreaterThan(0)
                ->and($component->get('confirmingDelete'))->toBeTrue();

            $component->call('deleteBracket', true);

            expect($category->bouts()->count())->toBe(0);
        });

        /** A contest being scored would vanish from under the mat screen. */
        it('refuses while a contest from the class is on a mat', function () {
            [$court, $bout] = boutOnMat();
            $category = $bout->weightCategory;

            Livewire::test(Bracket::class, ['weightCategory' => $category])
                ->call('deleteBracket')
                ->assertSee('on a mat');

            expect($category->bouts()->count())->toBeGreaterThan(0);
        });
    });

    /**
     * Regression: draw numbers are unique per category, and saveDraws() used
     * to write them one at a time in place. The moment two athletes swapped,
     * the first update tried to take a number the second was still holding and
     * the screen died on the constraint rather than saving.
     */
    it('lets two athletes swap draw numbers after the draw', function () {
        [$category, $athletes] = categoryWithAthletes(4);

        app(BracketGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set("draws.{$athletes[1]->id}", '2')
            ->set("draws.{$athletes[2]->id}", '1')
            ->call('saveDraws');

        expect($athletes[1]->refresh()->draw_number)->toBe(2)
            ->and($athletes[2]->refresh()->draw_number)->toBe(1);
    });

    it('says the bracket needs redrawing when the numbers change under one', function () {
        [$category, $athletes] = categoryWithAthletes(4);

        app(BracketGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set("draws.{$athletes[1]->id}", '2')
            ->set("draws.{$athletes[2]->id}", '1')
            ->call('saveDraws')
            // Asserted on what the operator actually sees: Livewire's test
            // harness ages the flash before session('status') can be read back.
            ->assertSee('Redraw the bracket for the new order to take effect.');
    });

    it('clears a draw number that is emptied', function () {
        [$category, $athletes] = categoryWithAthletes(3);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->set("draws.{$athletes[3]->id}", '')
            ->call('saveDraws');

        expect($athletes[3]->refresh()->draw_number)->toBeNull()
            ->and($athletes[1]->refresh()->draw_number)->toBe(1);
    });

    /**
     * Regression: the redraw used to write through models loaded before the
     * draw numbers were cleared. Eloquent persists only dirty attributes, so
     * an athlete redrawn the same number they already held looked unchanged
     * and was left with NULL.
     *
     * One eligible athlete already holding draw 1 makes that collision certain
     * rather than leaving it to the shuffle.
     */
    it('still writes a draw number that matches the one already held', function () {
        [$category] = categoryWithAthletes(1);

        expect($category->athletes()->first()->draw_number)->toBe(1);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('drawAtRandom');

        expect($category->athletes()->first()->refresh()->draw_number)->toBe(1)
            ->and($category->athletes()->whereNull('draw_number')->count())->toBe(0);
    });

    it('assigns a contiguous random draw to everyone who made weight', function () {
        [$category] = categoryWithAthletes(5);
        $category->athletes()->limit(1)->update(['weighin_status' => 'fail']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('drawAtRandom');

        $drawn = $category->athletes()->whereNotNull('draw_number')->pluck('draw_number')->sort()->values()->all();

        expect($drawn)->toBe([1, 2, 3, 4])
            ->and($category->athletes()->where('weighin_status', 'fail')->first()->draw_number)->toBeNull();
    });

    it('asks for confirmation before redrawing over results', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);
        runTournament($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate')
            ->assertSet('confirmingRegenerate', true);

        expect($category->bouts()->whereNotNull('winner_athlete_id')->count())->toBe(7);
    });
});

describe('medals screen', function () {
    it('shows the standings once a class is decided', function () {
        [$category, $athletes] = categoryWithAthletes(4);
        $athletes[1]->update(['noc_code' => 'UZB']);
        app(BracketGenerator::class)->generate($category);
        runTournament($category);

        $this->actingAs($this->viewer);

        Livewire::test(Medals::class, ['championship' => $category->ageCategory->championship])
            ->assertOk()
            ->assertSee('UZB')
            ->assertSee($athletes[1]->fullname);
    });
});
