<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Dashboard;
use App\Livewire\Operator\Draws;
use App\Livewire\Operator\Presentation;
use App\Models\Athlete;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BracketGenerator;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->operator = User::factory()->official()->create();
});

/** A drawn, published class — what an operator is meant to see. */
function publishedClass(int $athletes = 8): WeightCategory
{
    [$category] = categoryWithAthletes($athletes);

    app(BracketGenerator::class)->generate($category);

    $category->forceFill(['draw_published_at' => now()])->save();

    return $category->refresh();
}

describe('the bucket comes from the entry list', function () {
    it('seats the athletes who are actually registered', function (int $athletes, int $size, int $byes) {
        [$category] = categoryWithAthletes($athletes);

        if ($athletes < 2) {
            expect(fn () => app(BracketGenerator::class)->generate($category))->toThrow(RuntimeException::class);

            return;
        }

        $result = app(BracketGenerator::class)->generate($category);
        $category->refresh();

        expect($result['size'])->toBe($size)
            ->and($result['byes'])->toBe($byes)
            ->and($category->draw_bucket_size)->toBe($size)
            ->and($category->draw_athlete_count)->toBe($athletes)
            ->and($category->draw_bye_count)->toBe($byes)
            // The bracket holds exactly the seats it said it would.
            ->and($category->bouts()->where('round', 1)->count())->toBe(intdiv($size, 2));
    })->with([
        [1, 1, 0],
        [2, 2, 0],
        [3, 4, 1],
        [4, 4, 0],
        [5, 8, 3],
        [6, 8, 2],
        [7, 8, 1],
        [8, 8, 0],
        [13, 16, 3],
        [16, 16, 0],
        [17, 32, 15],
    ]);

    /** A class with nobody in it is refused, not seated with nobody. */
    it('refuses to draw an empty class', function () {
        $category = WeightCategory::factory()->create();

        expect(fn () => app(BracketGenerator::class)->generate($category))->toThrow(RuntimeException::class);
    });

    it('counts only athletes who were given a draw number', function () {
        [$category] = categoryWithAthletes(6);

        // Registered but not drawn — no number, so not in the bracket.
        Athlete::factory()->count(3)->create([
            'championship_id' => $category->ageCategory->championship_id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
            'draw_number' => null,
        ]);

        $result = app(BracketGenerator::class)->generate($category);

        expect($result['athletes'])->toBe(6)
            ->and($result['size'])->toBe(8)
            ->and($result['byes'])->toBe(2);
    });

    it('creates no athlete rows to fill the empty seats', function () {
        [$category] = categoryWithAthletes(5);

        $before = Athlete::count();
        app(BracketGenerator::class)->generate($category);

        expect(Athlete::count())->toBe($before)
            // The three empty seats are byes, not people.
            ->and($category->bouts()->where('is_bye', true)->count())->toBe(3);
    });
});

describe('the operator list', function () {
    beforeEach(fn () => $this->actingAs($this->operator));

    it('shows a published class with its stored figures', function () {
        $category = publishedClass(13);

        Livewire::test(Draws::class)
            ->assertSee('Published')
            ->assertSee('Present draw')
            ->assertSee('bracket of 16');
    });

    /** Nothing about an unpublished draw: not a name, not a pairing. */
    it('shows an unpublished class as waiting and nothing else', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);

        $athlete = $category->athletes()->firstOrFail();

        Livewire::test(Draws::class)
            ->assertSee('Waiting for publication')
            ->assertSee('Not yet published')
            ->assertDontSee('Present draw')
            ->assertDontSee($athlete->fullname);
    });

    it('filters without widening what may be seen', function () {
        $published = publishedClass(4);
        [$other] = categoryWithAthletes(4);

        Livewire::test(Draws::class)
            ->set('status', 'published')
            ->assertSee($published->ageCategory->championship->title)
            ->set('gender', 'F')
            ->assertDontSee('Present draw');
    });

    it('leaves an archived championship out', function () {
        $category = publishedClass(4);
        $category->ageCategory->championship->forceFill(['archived_at' => now()])->save();

        Livewire::test(Draws::class)->assertDontSee('Present draw');
    });
});

describe('presenting a draw', function () {
    it('opens a published draw and shows the stored table', function () {
        $category = publishedClass(13);
        $athlete = $category->drawnAthletes()->firstOrFail();

        $this->actingAs($this->operator)
            ->get(route('operator.draws.show', $category))
            ->assertOk()
            ->assertSee($athlete->fullname)
            ->assertSee('Replay presentation');
    });

    it('refuses a draw that has not been published', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        $this->actingAs($this->operator)
            ->get(route('operator.draws.show', $category))
            ->assertForbidden();
    });

    it('refuses a category with no draw at all', function () {
        $category = WeightCategory::factory()->create();

        $this->actingAs($this->operator)
            ->get(route('operator.draws.show', $category))
            ->assertForbidden();
    });

    it('refuses an archived championship', function () {
        $category = publishedClass(4);
        $category->ageCategory->championship->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->operator)
            ->get(route('operator.draws.show', $category))
            ->assertForbidden();
    });

    /** Opening a draw reads it. It does not draw it again. */
    it('generates nothing when the page is opened', function () {
        $category = publishedClass(13);

        $before = $category->bouts()->pluck('athlete_a_id', 'id')->toArray();
        $version = $category->draw_version;

        $this->actingAs($this->operator)->get(route('operator.draws.show', $category))->assertOk();
        $this->actingAs($this->operator)->get(route('operator.draws.show', $category))->assertOk();

        expect($category->refresh()->draw_version)->toBe($version)
            ->and($category->bouts()->pluck('athlete_a_id', 'id')->toArray())->toBe($before);
    });

    it('keeps the published table when the entry list moves afterwards', function () {
        $category = publishedClass(8);
        $stored = $category->draw_bucket_size;

        Athlete::factory()->drawn(9)->create([
            'championship_id' => $category->ageCategory->championship_id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
        ]);

        $board = Livewire::actingAs($this->operator)->test(Presentation::class, ['weightCategory' => $category->refresh()]);

        expect($category->refresh()->draw_bucket_size)->toBe($stored)
            ->and($board->viewData('bouts')->count())->toBe($category->bouts()->count());
    });

    it('says so when the draw was republished under it', function () {
        $category = publishedClass(8);

        $board = Livewire::actingAs($this->operator)->test(Presentation::class, ['weightCategory' => $category]);
        expect($board->viewData('stale'))->toBeFalse();

        // The admin redraws and publishes again while the page is open.
        app(BracketGenerator::class)->generate($category->refresh(), true, true);
        $category->refresh()->forceFill(['draw_published_at' => now()])->save();

        expect($board->call('$refresh')->viewData('stale'))->toBeTrue();
    });
});

describe('what an operator cannot do', function () {
    beforeEach(fn () => $this->actingAs($this->operator));

    it('cannot reach the working bracket screen', function () {
        $category = publishedClass(4);

        $this->get(route('bracket.show', $category))->assertForbidden();
    });

    it('cannot draw, redraw, publish, withdraw or lock', function () {
        $category = publishedClass(4);

        foreach ([
            ['generate', []],
            ['drawAtRandom', []],
            ['saveDraws', []],
            ['deleteBracket', []],
            ['publishDraw', []],
            ['withdrawDraw', []],
            ['toggleDrawLock', []],
        ] as [$method, $args]) {
            Livewire::test(Bracket::class, ['weightCategory' => $category])
                ->call($method, ...$args)
                ->assertForbidden();
        }

        expect($category->refresh()->isDrawPublished())->toBeTrue()
            ->and($category->draw_version)->toBe(1);
    });

    /** The presentation component has no method that writes at all. */
    it('has no mutating action on the presentation page', function () {
        $category = publishedClass(4);

        $methods = collect((new ReflectionClass(Presentation::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            // Declared here only: Livewire's base class brings its own, and
            // none of those are actions a browser can call.
            ->filter(fn (ReflectionMethod $m) => $m->class === Presentation::class)
            ->map(fn (ReflectionMethod $m) => $m->name)
            ->reject(fn (string $name) => str_starts_with($name, '__'));

        expect($methods->diff(['mount', 'render']))->toBeEmpty();
    });
});

describe('publication is the admin\'s decision', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('does not publish a draw merely by generating it', function () {
        [$category] = categoryWithAthletes(8);

        Livewire::test(Bracket::class, ['weightCategory' => $category])->call('generate');

        expect($category->refresh()->isDrawPublished())->toBeFalse();
    });

    it('publishes and withdraws on request', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        $screen = Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()]);

        $screen->call('publishDraw');
        expect($category->refresh()->isDrawPublished())->toBeTrue();

        $screen->call('withdrawDraw');
        expect($category->refresh()->isDrawPublished())->toBeFalse();
    });

    /** A published table is one other people work from: replacing it is a decision. */
    it('asks again before replacing a published draw', function () {
        $category = publishedClass(8);

        $screen = Livewire::test(Bracket::class, ['weightCategory' => $category])->call('generate');

        expect($screen->get('confirmingReplacePublished'))->toBeTrue()
            ->and($category->refresh()->draw_version)->toBe(1);

        $screen->call('generate', false, true);

        expect($category->refresh()->draw_version)->toBe(2)
            // A new table has not been approved by anybody yet.
            ->and($category->isDrawPublished())->toBeFalse();
    });

    it('refuses to redraw a locked draw at all', function () {
        $category = publishedClass(8);
        $category->forceFill(['draw_locked_at' => now()])->save();

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->call('generate', false, true);

        expect($category->refresh()->draw_version)->toBe(1);
    });

    it('clears the metadata when the bracket is deleted', function () {
        $category = publishedClass(8);

        Livewire::test(Bracket::class, ['weightCategory' => $category])->call('deleteBracket');

        $category->refresh();

        expect($category->hasDraw())->toBeFalse()
            ->and($category->isDrawPublished())->toBeFalse()
            ->and($category->draw_bucket_size)->toBeNull();
    });

    it('notices when the entry list has moved under a drawn class', function () {
        $category = publishedClass(8);

        expect($category->drawIsStale())->toBeFalse();

        Athlete::factory()->drawn(9)->create([
            'championship_id' => $category->ageCategory->championship_id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
        ]);

        expect($category->refresh()->drawIsStale())->toBeTrue();
    });
});

describe('links an operator can actually follow', function () {
    beforeEach(fn () => $this->actingAs($this->operator));

    /**
     * The screen that prompted this: a drawn class offered Open, which goes to
     * the working draw screen. An operator following it got a 403 — a link
     * nobody can follow is worse than no link at all.
     */
    it('offers Present instead of Open on the entries board', function () {
        $category = publishedClass(8);
        $championship = $category->ageCategory->championship;

        $this->get(route('entries.index', $championship))
            ->assertOk()
            ->assertSee('Present')
            ->assertDontSee(route('bracket.show', $category));
    });

    it('offers nothing at all while the draw is unpublished', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        $this->get(route('entries.index', $category->ageCategory->championship))
            ->assertOk()
            ->assertDontSee(route('bracket.show', $category))
            ->assertDontSee(route('operator.draws.show', $category));
    });

    it('points the weight tiles at the published table', function () {
        $category = publishedClass(8);

        $this->get(route('championships.show', $category->ageCategory->championship))
            ->assertOk()
            ->assertSee(route('operator.draws.show', $category))
            ->assertDontSee(route('bracket.show', $category));
    });

    it('keeps Open for the people who run the draw', function () {
        $category = publishedClass(8);

        $this->actingAs($this->admin)
            ->get(route('entries.index', $category->ageCategory->championship))
            ->assertOk()
            ->assertSee('Open')
            ->assertSee(route('bracket.show', $category));
    });

    it('does not offer to draw a bracket from the dashboard', function () {
        [$category] = categoryWithAthletes(8);

        $steps = collect(Livewire::test(Dashboard::class)->viewData('championships'))
            ->flatMap(fn (array $row) => $row['next_steps'] ?? []);

        expect($steps->pluck('route'))->not->toContain('bracket.show');
    });
});

/**
 * The lock says "do not draw this class again". It has to be possible to stop
 * saying that.
 */
describe('a locked draw is not a dead end', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    /**
     * The trap: deleting a bracket cleared everything that described it except
     * the lock, and the unlock control was only drawn when a bracket existed.
     * So the class refused to be redrawn over a bracket that was not there,
     * and the one control that would have allowed it was off the screen.
     */
    it('does not leave a class locked against a bracket that is gone', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('toggleDrawLock')
            ->call('deleteBracket', true);

        expect($category->refresh()->isDrawLocked())->toBeFalse()
            ->and($category->bouts()->count())->toBe(0);

        // And the point of all that: it can be drawn again.
        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate');

        expect($category->refresh()->bouts()->count())->toBeGreaterThan(0);
    });

    /** A class already stuck that way has the way out on its own screen. */
    it('offers the unlock on a locked class with no bracket', function () {
        [$category] = categoryWithAthletes(8);
        $category->forceFill(['draw_locked_at' => now()])->save();

        Livewire::test(Bracket::class, ['weightCategory' => $category->refresh()])
            ->assertSee('Unlock draw');
    });

    /** Locking still does what it is for while the draw exists. */
    it('still refuses to redraw a locked class', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('toggleDrawLock')
            ->call('generate', true);

        expect($category->refresh()->isDrawLocked())->toBeTrue();
    });
});
