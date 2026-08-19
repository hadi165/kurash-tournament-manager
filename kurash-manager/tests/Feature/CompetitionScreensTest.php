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
            'athletes.index' => route('athletes.index', $ageCategory),
            'weighin.index' => route('weighin.index', $ageCategory),
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
        $this->get(route('athletes.index', $ageCategory))->assertOk();
        $this->get(route('weighin.index', $ageCategory))->assertOk();
        $this->get(route('bracket.show', $weightCategory))->assertOk();
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

        Livewire::test(WeighIn::class, ['ageCategory' => $category->ageCategory])
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
            ->set('ageCategoryName', 'Men Senior')
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
            ->set('ageCategoryName', 'Men Senior')
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
            ->set('ageCategoryName', 'Men Senior')
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

        Livewire::test(Registration::class, ['ageCategory' => $category->ageCategory])
            ->set('fullname', 'Ghader Nasb')
            ->set('noc_code', 'afg')
            ->set('gender', 'M')
            ->set('weight_category_id', $category->id)
            ->call('save')
            ->assertHasNoErrors();

        $athlete = Athlete::where('fullname', 'Ghader Nasb')->first();

        expect($athlete)->not->toBeNull()
            ->and($athlete->noc_code)->toBe('AFG')          // upper-cased
            ->and($athlete->ika_id)->toMatch('/^IKA\d{6}$/');
    });

    /**
     * Guards against the IDOR in the old registration page, whose UPDATE was
     * scoped by row id alone.
     */
    it('refuses a weight class from another championship', function () {
        [$mine] = categoryWithAthletes(0);
        [$theirs] = categoryWithAthletes(0, '-73');

        Livewire::test(Registration::class, ['ageCategory' => $mine->ageCategory])
            ->set('fullname', 'Intruder')
            ->set('noc_code', 'UZB')
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

        Livewire::test(Registration::class, ['ageCategory' => $ageCategory])
            ->call('edit', $athlete->id)
            ->set('weight_category_id', $to->id)
            ->call('save');

        expect($athlete->refresh()->weight_category_id)->toBe($to->id)
            ->and($athlete->draw_number)->toBeNull();
    });
});

describe('weigh-in', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('passes a weight inside the class and fails one outside', function (float $kg, string $expected) {
        [$category] = categoryWithAthletes(1);   // -66 class, min 60 max 66
        $athlete = $category->athletes()->first();

        Livewire::test(WeighIn::class, ['ageCategory' => $category->ageCategory])
            ->set("weights.{$athlete->id}", (string) $kg)
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_status)->toBe($expected)
            ->and((float) $athlete->weighin_kg)->toBe($kg);
    })->with([
        'at the limit' => [66.0, 'pass'],
        'inside tolerance' => [65.6, 'pass'],
        'below tolerance' => [64.9, 'fail'],
        'over the limit' => [66.4, 'fail'],
    ]);

    it('rejects a non-numeric entry without touching the record', function () {
        [$category] = categoryWithAthletes(1);
        $athlete = $category->athletes()->first();

        Livewire::test(WeighIn::class, ['ageCategory' => $category->ageCategory])
            ->set("weights.{$athlete->id}", 'heavy')
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_kg)->toBeNull();
    });
});

describe('bracket screen', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('draws a bracket and records a result through to the podium', function () {
        [$category, $athletes] = categoryWithAthletes(4);

        $component = Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate');

        expect($category->bouts()->count())->toBe(3);

        // Lower draw number wins every bout.
        foreach ([1, 2] as $round) {
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
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        runTournament($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate')
            ->assertSet('confirmingRegenerate', true);

        expect($category->bouts()->whereNotNull('winner_athlete_id')->count())->toBe(3);
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
