<?php

use App\Livewire\Competition\Dashboard;
use App\Livewire\Competition\LiveMats;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\DrawGenerator;
use App\Services\FightOrderScheduler;
use App\Support\ChampionshipStatus;
use App\Support\DisplayCache;
use App\Support\TournamentFormat;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
});

/**
 * The dashboard exists to say what one competition is waiting on and what is
 * happening on its mats, so these test the claim each panel makes rather than
 * the markup around it.
 */
describe('choosing the championship', function () {
    it('offers to create one when none are open', function () {
        Livewire::test(Dashboard::class)
            ->assertSee('No competitions are open')
            ->assertSee('Create a championship');
    });

    it('leaves archived championships out', function () {
        // Written before archiving: ArchivedChampionshipGuard refuses every
        // write to an archived championship, factories included.
        $championship = Championship::factory()->create(['title' => 'Finished Cup']);
        $championship->archive();

        Livewire::test(Dashboard::class)
            ->assertSee('No competitions are open')
            ->assertDontSee('Finished Cup');
    });

    it('shows the championship being run today', function () {
        Championship::factory()->create([
            'title' => 'Last Year',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(2)->toDateString(),
        ]);

        Championship::factory()->create([
            'title' => 'Running Now',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
        ]);

        expect(Livewire::test(Dashboard::class)->viewData('championship')->title)
            ->toBe('Running Now');
    });

    it('falls back to the nearest upcoming one when nothing is running', function () {
        Championship::factory()->create([
            'title' => 'Later',
            'starts_on' => now()->addMonths(3)->toDateString(),
            'ends_on' => now()->addMonths(3)->addDays(2)->toDateString(),
        ]);

        Championship::factory()->create([
            'title' => 'Sooner',
            'starts_on' => now()->addWeek()->toDateString(),
            'ends_on' => now()->addWeek()->addDays(2)->toDateString(),
        ]);

        expect(Livewire::test(Dashboard::class)->viewData('championship')->title)
            ->toBe('Sooner');
    });

    it('honours a championship chosen in the URL', function () {
        Championship::factory()->create(['title' => 'Default Pick']);
        $other = Championship::factory()->create([
            'title' => 'Chosen One',
            'starts_on' => now()->addYear()->toDateString(),
            'ends_on' => now()->addYear()->addDays(2)->toDateString(),
        ]);

        expect(Livewire::test(Dashboard::class, ['selected' => $other->id])->viewData('championship')->title)
            ->toBe('Chosen One');
    });

    /**
     * The property arrives from the browser, so it is a request for a
     * championship and not a key. An id that names an archived competition, or
     * nothing at all, falls back rather than rendering something the operator
     * was never shown.
     */
    it('ignores an id that is not an open championship', function () {
        $open = Championship::factory()->create(['title' => 'Still Running']);

        $archived = Championship::factory()->create(['title' => 'Sealed']);
        $archived->archive();

        expect(Livewire::test(Dashboard::class, ['selected' => $archived->id])->viewData('championship')->id)
            ->toBe($open->id);

        expect(Livewire::test(Dashboard::class, ['selected' => 999_999])->viewData('championship')->id)
            ->toBe($open->id);
    });

    it('only offers a selector when there is a choice', function () {
        Championship::factory()->create(['title' => 'Only One']);

        expect(Livewire::test(Dashboard::class)->viewData('openChampionships'))->toHaveCount(1);

        Championship::factory()->create(['title' => 'A Second']);

        expect(Livewire::test(Dashboard::class)->viewData('openChampionships'))->toHaveCount(2);
    });
});

describe('the status badge', function () {
    it('reads Upcoming before the first day', function () {
        Championship::factory()->create([
            'starts_on' => now()->addWeek()->toDateString(),
            'ends_on' => now()->addWeek()->addDays(2)->toDateString(),
        ]);

        expect(Livewire::test(Dashboard::class)->viewData('status'))
            ->toBe(ChampionshipStatus::Upcoming);
    });

    it('reads Setup once it has started with nothing decided', function () {
        drawableClass(4);

        expect(Livewire::test(Dashboard::class)->viewData('status'))
            ->toBe(ChampionshipStatus::Setup);
    });

    it('reads Live while a contest is on a mat', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);
        liveBoutOn($category);

        expect(Livewire::test(Dashboard::class)->viewData('status'))
            ->toBe(ChampionshipStatus::Live);
    });

    it('reads In progress when results exist but no mat is occupied', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);
        decideOne($category);

        expect(Livewire::test(Dashboard::class)->viewData('status'))
            ->toBe(ChampionshipStatus::InProgress);
    });

    it('reads Completed once every contest is decided', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);
        decideEverything($category->ageCategory->championship);

        expect(Livewire::test(Dashboard::class)->viewData('status'))
            ->toBe(ChampionshipStatus::Completed);
    });

    /** Dates cannot overrule the bouts: a semi-final still open is not finished. */
    it('does not call a championship complete because its dates have passed', function () {
        $championship = Championship::factory()->create([
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->subMonth()->addDays(2)->toDateString(),
        ]);

        $category = drawableClass(4, championship: $championship);
        app(BracketGenerator::class)->generate($category);

        expect(Livewire::test(Dashboard::class)->viewData('status'))
            ->toBe(ChampionshipStatus::Setup);
    });
});

describe('attention required', function () {
    it('asks for weight classes before anything else', function () {
        Championship::factory()->create(['title' => 'Empty Cup']);

        Livewire::test(Dashboard::class)
            ->assertSee('No weight classes have been set up')
            ->assertDontSee('has athletes but no draw');
    });

    it('asks for athletes once the classes exist', function () {
        WeightCategory::factory()->create();

        Livewire::test(Dashboard::class)->assertSee('Nobody is registered yet');
    });

    /**
     * The weigh-in is a screen per competition, so a single total could not
     * link anywhere useful. One row each, each pointing at its own form.
     */
    it('separates athletes awaiting the scale by competition', function () {
        $championship = Championship::factory()->create(['genders' => ['M', 'F', 'X']]);

        drawableClass(2, 'pending', $championship, '-90', 'M');
        drawableClass(3, 'pending', $championship, '-63', 'F');
        drawableClass(1, 'pending', $championship, '-70', 'X');

        $attention = collect(Livewire::test(Dashboard::class)->viewData('attention'));

        expect($attention->pluck('key'))
            ->toContain('weigh-in-M')
            ->toContain('weigh-in-F')
            ->toContain('weigh-in-X');

        foreach (['M', 'F', 'X'] as $competition) {
            $row = $attention->firstWhere('key', "weigh-in-{$competition}");

            expect($row['route'])->toBe('weighin.index')
                ->and($row['params']['competition'])->toBe($competition);
        }
    });

    it('reports a class that has athletes but no draw', function () {
        drawableClass(4);

        Livewire::test(Dashboard::class)
            ->assertSee('weight class has athletes but no draw')
            ->assertSee('Draw -90 kg');
    });

    /**
     * The bug this replaced: a one-athlete class is drawn by an administrative
     * placement and has no contests at all, so a bouts_count of zero called it
     * undrawn and the dashboard offered to draw it again for the rest of the
     * competition.
     */
    it('does not call a one-athlete placement undrawn', function () {
        $category = drawableClass(1);
        app(DrawGenerator::class)->generate($category);

        expect($category->refresh()->drawFormat())->toBe(TournamentFormat::Placement)
            ->and($category->bouts()->count())->toBe(0)
            ->and($category->hasDraw())->toBeTrue();

        $attention = collect(Livewire::test(Dashboard::class)->viewData('attention'));

        expect($attention->pluck('key'))->not->toContain('undrawn');
    });

    it('reports a generated draw that has not been published', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Dashboard::class)->assertSee('has been generated but not published');
    });

    it('stops reporting it once it is published', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();

        Livewire::test(Dashboard::class)->assertDontSee('has been generated but not published');
    });

    it('asks for a running order once a draw exists', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Dashboard::class)
            ->assertDontSee('has athletes but no draw')
            ->assertSee('Build the running order');
    });

    /** Exactly the state that produced "the fight order has no button". */
    it('says when there are no mats to send a contest to', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);

        Livewire::test(Dashboard::class)->assertSee('No mats are active');
    });

    it('stops nagging once everything is set up', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;

        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();
        app(FightOrderScheduler::class)->schedule($championship);
        Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true]);

        Livewire::test(Dashboard::class)
            ->assertSee('Nothing is blocking the competition')
            ->assertDontSee('No mats are active')
            ->assertDontSee('Build the running order')
            ->assertDontSee('has athletes but no draw');
    });

    /**
     * A contest being fought is the system working. Listing it beside the
     * genuine blockers taught operators to skim the whole panel.
     */
    it('does not treat a live contest as something needing attention', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;

        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();
        app(FightOrderScheduler::class)->schedule($championship);
        liveBoutOn($category);

        expect(Livewire::test(Dashboard::class)->viewData('attention'))->toBe([]);
    });
});

describe('live mats', function () {
    /**
     * Being assigned to a mat and being called onto it are two actions. The
     * gap between them was announcing contests as live, and athletes were
     * being called early off the venue screen.
     */
    it('only counts a contest that has actually started', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        app(BracketGenerator::class)->generate($category);

        $court = Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true]);
        $bout = readyBout($category);
        $bout->update(['court_id' => $court->id, 'status' => Bout::STATUS_SCHEDULED]);

        $mats = Livewire::test(LiveMats::class, ['championshipId' => $championship->id])->viewData('mats');

        expect($mats)->toHaveCount(1)
            ->and($mats->first()->isLive())->toBeFalse()
            ->and($mats->first()->bout)->toBeNull();

        $bout->update(['status' => Bout::STATUS_ON_COURT]);

        $mats = Livewire::test(LiveMats::class, ['championshipId' => $championship->id])->viewData('mats');

        expect($mats->first()->isLive())->toBeTrue()
            ->and($mats->first()->bout->id)->toBe($bout->id);
    });

    it('leaves a mat that is not in service out entirely', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        app(BracketGenerator::class)->generate($category);

        Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true, 'number' => 1]);
        $retired = Court::factory()->create(['championship_id' => $championship->id, 'is_active' => false, 'number' => 2]);

        readyBout($category)->update(['court_id' => $retired->id, 'status' => Bout::STATUS_ON_COURT]);

        $mats = Livewire::test(LiveMats::class, ['championshipId' => $championship->id])->viewData('mats');

        expect($mats)->toHaveCount(1)
            ->and($mats->first()->court->number)->toBe(1)
            ->and($mats->first()->isLive())->toBeFalse();
    });

    it('shows every active mat, free ones included', function () {
        $championship = Championship::factory()->create();
        Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true, 'number' => 1, 'name' => null]);
        Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true, 'number' => 2, 'name' => null]);

        Livewire::test(LiveMats::class, ['championshipId' => $championship->id])
            ->assertSee('Mat 1')
            ->assertSee('Mat 2')
            ->assertSee('Free');
    });

    /**
     * false is the column default, so without this every contest sent to a mat
     * and not yet begun flew "Clock stopped" — a warning that is always on.
     */
    it('does not call a clock stopped before it has ever run', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        app(BracketGenerator::class)->generate($category);

        $bout = liveBoutOn($category);
        expect($bout->clock_updated_at)->toBeNull();

        $mats = Livewire::test(LiveMats::class, ['championshipId' => $championship->id])->viewData('mats');

        expect($mats->first()->isLive())->toBeTrue()
            ->and($mats->first()->clockStopped())->toBeFalse();

        // Started, then paused: now it genuinely is a stopped clock.
        $bout->update(['clock_seconds_left' => 90, 'clock_running' => false, 'clock_updated_at' => now()]);

        $mats = Livewire::test(LiveMats::class, ['championshipId' => $championship->id])->viewData('mats');

        expect($mats->first()->clockStopped())->toBeTrue();
    });

    /** Jazzo is reported in its own right; a mat must not say one thing twice. */
    it('reports jazzo instead of a stopped clock', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        app(BracketGenerator::class)->generate($category);

        liveBoutOn($category)->update([
            'clock_seconds_left' => 90,
            'clock_running' => false,
            'clock_updated_at' => now(),
            'jazzo_called_at' => now(),
        ]);

        $mat = Livewire::test(LiveMats::class, ['championshipId' => $championship->id])
            ->viewData('mats')->first();

        expect($mat->isInJazzo())->toBeTrue()
            ->and($mat->clockStopped())->toBeFalse();
    });

    it('says so when no mat has been set up', function () {
        $championship = Championship::factory()->create();

        Livewire::test(LiveMats::class, ['championshipId' => $championship->id])
            ->assertSee('No mats are active yet');
    });
});

describe('coming up', function () {
    it('lists the next five ready contests in running order', function () {
        $category = drawableClass(16);
        $championship = $category->ageCategory->championship;

        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($championship);

        $comingUp = Livewire::test(Dashboard::class)->viewData('comingUp');

        expect($comingUp)->toHaveCount(5)
            ->and($comingUp->pluck('fight_number')->all())
            ->toBe($comingUp->pluck('fight_number')->sort()->values()->all());

        // And they really are the first five, not any five.
        $expected = $championship->bouts()
            ->readyToFight()->whereNotNull('fight_number')
            ->orderBy('fight_number')->limit(5)->pluck('fight_number')->all();

        expect($comingUp->pluck('fight_number')->all())->toBe($expected);
    });

    it('leaves out contests already on a mat', function () {
        $category = drawableClass(8);
        $championship = $category->ageCategory->championship;

        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($championship);

        $onMat = liveBoutOn($category);

        expect(Livewire::test(Dashboard::class)->viewData('comingUp')->pluck('id'))
            ->not->toContain($onMat->id);
    });

    it('leaves out byes and contests missing an athlete', function () {
        // Five athletes in a bracket of eight: the byes are rows, not contests.
        $category = drawableClass(5);
        $championship = $category->ageCategory->championship;

        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($championship);

        $comingUp = Livewire::test(Dashboard::class)->viewData('comingUp');

        foreach ($comingUp as $bout) {
            expect($bout->is_bye)->toBeFalse()
                ->and($bout->athlete_a_id)->not->toBeNull()
                ->and($bout->athlete_b_id)->not->toBeNull();
        }
    });

    /** Two different problems with two different fixes. */
    it('tells an unbuilt running order apart from an empty one', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Dashboard::class)
            ->assertSee('The running order has not been built yet')
            ->assertDontSee('every ready contest is on a mat');

        app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);
        decideEverything($category->ageCategory->championship);

        Livewire::test(Dashboard::class)
            ->assertSee('every ready contest is on a mat')
            ->assertDontSee('The running order has not been built yet');
    });
});

describe('the workflow figures', function () {
    it('counts registered, passed and drawn as three different questions', function () {
        $category = drawableClass(3);

        // Registered and turned away at the scale, so holding no draw number.
        // A failed athlete who kept one is a state the draw refuses outright —
        // see WeightCategory::ineligibleNumberedAthletes().
        Athlete::factory()->create([
            'championship_id' => $category->ageCategory->championship_id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
            'weighin_status' => 'fail',
        ]);

        app(BracketGenerator::class)->generate($category->refresh());

        $workflow = Livewire::test(Dashboard::class)->viewData('workflow');

        expect($workflow['registered'])->toBe(4)
            ->and($workflow['passed'])->toBe(3)
            ->and($workflow['drawn'])->toBe(3);
    });

    /**
     * The stored snapshot, not today's entry list: an athlete registered after
     * the draw was generated is not in that draw, and this figure must not move
     * until somebody regenerates it.
     */
    it('reports the field the draw was generated with', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        app(BracketGenerator::class)->generate($category);

        Athlete::factory()->drawn(5)->create([
            'championship_id' => $championship->id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
            'weighin_status' => 'pass',
        ]);

        $workflow = Livewire::test(Dashboard::class)->viewData('workflow');

        expect($workflow['registered'])->toBe(5)
            ->and($workflow['passed'])->toBe(5)
            ->and($workflow['drawn'])->toBe(4);
    });

    /** Draws made before the column existed have only the live field to report. */
    it('falls back to the drawn field when no snapshot was recorded', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_athlete_count' => null])->save();

        expect(Livewire::test(Dashboard::class)->viewData('workflow')['drawn'])->toBe(4);
    });

    it('reports contest progress with byes excluded', function () {
        // 5 athletes: 4 real contests once the byes are resolved.
        $category = drawableClass(5);
        app(BracketGenerator::class)->generate($category);

        $progress = Livewire::test(Dashboard::class)->viewData('progress');

        expect($progress['total'])->toBe(4)
            ->and($progress['decided'])->toBe(0)
            ->and($progress['percent'])->toBe(0);

        decideOne($category);

        $progress = Livewire::test(Dashboard::class)->viewData('progress');

        expect($progress['decided'])->toBe(1)
            ->and($progress['percent'])->toBe(25);
    });
});

describe('the medal snapshot', function () {
    it('says plainly that nothing has been decided', function () {
        $category = drawableClass(4);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Dashboard::class)->assertSee('No class has been decided yet');
    });

    it('shows the leading three NOCs and how much is decided', function () {
        $championship = Championship::factory()->create();

        // A two-athlete class is a round robin of one contest, which is the
        // cheapest complete podium there is.
        foreach (['AAA', 'BBB', 'CCC', 'DDD'] as $index => $noc) {
            $category = drawableClass(2, championship: $championship, label: "-6{$index}");
            $category->athletes()->update(['noc_code' => $noc]);

            app(DrawGenerator::class)->generate($category->refresh());
            decideEverything($championship);
        }

        $medals = Livewire::test(Dashboard::class)->viewData('medals');

        expect($medals['leaders'])->toHaveCount(3)
            ->and($medals['decided'])->toBe(4)
            ->and($medals['total'])->toBe(4);
    });
});

describe('the venue displays', function () {
    it('links to each standalone screen by its named route', function () {
        $championship = Championship::factory()->create();

        Livewire::test(Dashboard::class)
            ->assertSee(route('display.mats', $championship))
            ->assertSee(route('display.fight-order', $championship))
            ->assertSee(route('display.medals', $championship));
    });

    /**
     * They hang on a projector, so they open in their own window and are never
     * fetched through wire:navigate — which would drop a cached standalone
     * document into the operator layout.
     */
    it('opens them as standalone pages', function () {
        $championship = Championship::factory()->create();

        $html = Livewire::test(Dashboard::class)->html();
        $matsUrl = route('display.mats', $championship);

        expect($html)->toContain('target="_blank"')->toContain('rel="noopener"');

        preg_match('/<a[^>]+href="'.preg_quote($matsUrl, '/').'"[^>]*>/', $html, $anchor);

        expect($anchor)->not->toBeEmpty()
            ->and($anchor[0])->toContain('target="_blank"')
            ->and($anchor[0])->not->toContain('wire:navigate');
    });

    it('says whether the screens need a sign-in', function () {
        Championship::factory()->create();

        config(['display.public' => false]);
        Livewire::test(Dashboard::class)->assertSee('Sign-in required');

        config(['display.public' => true]);
        Livewire::test(Dashboard::class)->assertSee('Public — no sign-in needed');
    });
});

describe('what a read-only account sees', function () {
    it('is reachable by a viewer', function () {
        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->get(route('dashboard'))
            ->assertOk();
    });

    /**
     * The problem is still stated — a viewer watching the desk needs to know
     * why nothing is moving — but the button that fixes it is not offered to
     * somebody the gate would refuse.
     */
    it('states the blockers without offering the actions', function () {
        drawableClass(4);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $attention = collect(Livewire::test(Dashboard::class)->viewData('attention'));

        expect($attention)->not->toBeEmpty()
            ->and($attention->pluck('route')->filter())->toBeEmpty()
            ->and($attention->pluck('label')->filter())->toBeEmpty();

        Livewire::test(Dashboard::class)
            ->assertSee('has athletes but no draw')
            ->assertDontSee('Draw -90 kg');
    });
});

/**
 * The dashboard sends operators to the venue screens and reports what they
 * show, so a screen serving a stale cache is now a wrong answer on this page
 * too. These cover the writes that change a display without touching a bout,
 * which is what the bout observer cannot see.
 */
describe('the venue screens stay in step', function () {
    it('invalidates after the running order is built in bulk', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        app(BracketGenerator::class)->generate($category);

        $before = DisplayCache::version($championship->id);
        app(FightOrderScheduler::class)->schedule($championship);

        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);
    });

    it('invalidates after the running order is cleared in bulk', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($championship);

        $before = DisplayCache::version($championship->id);
        app(FightOrderScheduler::class)->clear($championship);

        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);
    });

    it('invalidates when a mat is renamed or taken out of service', function () {
        $championship = Championship::factory()->create();
        $court = Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true]);

        $before = DisplayCache::version($championship->id);
        $court->update(['name' => 'Centre Mat']);
        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);

        $before = DisplayCache::version($championship->id);
        $court->update(['is_active' => false]);
        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);
    });

    it('invalidates when an athlete name or NOC is corrected', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;
        $athlete = $category->athletes()->first();

        $before = DisplayCache::version($championship->id);
        $athlete->update(['fullname' => 'Corrected Name']);
        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);

        // Set to a known code first: an "update" to the value already stored
        // changes nothing, so it must not be what this asserts on.
        $athlete->update(['noc_code' => 'AAA']);

        $before = DisplayCache::version($championship->id);
        $athlete->update(['noc_code' => 'BBB']);
        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);
    });

    it('invalidates when a weight class is relabelled', function () {
        $category = drawableClass(4);
        $championship = $category->ageCategory->championship;

        $before = DisplayCache::version($championship->id);
        $category->update(['label' => '-100']);

        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);
    });

    /** A one-athlete podium is this column and nothing else — no bout is written. */
    it('invalidates when the sole athlete of a class is placed', function () {
        $category = drawableClass(1);
        $championship = $category->ageCategory->championship;
        app(DrawGenerator::class)->generate($category);

        $before = DisplayCache::version($championship->id);
        app(DrawGenerator::class)->placeSoleAthlete($category->refresh(), $this->user);

        expect(DisplayCache::version($championship->id))->toBeGreaterThan($before);
    });

    /** A key nobody can see is not a reason to re-render every screen. */
    it('leaves the version alone for a change the hall cannot see', function () {
        $championship = Championship::factory()->create();
        $court = Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true]);

        $before = DisplayCache::version($championship->id);
        $court->update(['scoreboard_api_key' => 'a-new-secret']);

        expect(DisplayCache::version($championship->id))->toBe($before);
    });
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A weight class with a field, in a championship of its own unless given one.
 *
 * `$competition` is the division's, not the athlete's: athletes.gender is only
 * ever M or F, while a division may be run open, and every screen scoped "by
 * competition" is scoped by the age category.
 */
function drawableClass(
    int $count,
    string $weighIn = 'pass',
    ?Championship $championship = null,
    string $label = '-90',
    string $competition = 'M',
): WeightCategory {
    $championship ??= Championship::factory()->create();

    // Found, not blindly created: age categories are unique per championship on
    // both their name and their (gender, age group), so a second class in the
    // same competition must hang off the division that is already there.
    $ageCategory = $championship->ageCategories()->where('gender', $competition)->first()
        ?? AgeCategory::factory()->create([
            'championship_id' => $championship->id,
            'gender' => $competition,
        ]);

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => $label,
    ]);

    foreach (range(1, $count) as $draw) {
        Athlete::factory()->drawn($draw)->create([
            'championship_id' => $championship->id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'weighin_status' => $weighIn,
        ]);
    }

    return $category->refresh();
}

/** A contest in this class with both athletes present and no result. */
function readyBout(WeightCategory $category): Bout
{
    return $category->bouts()->readyToFight()->orderBy('id')->firstOrFail();
}

/** Put one contest on an active mat and start it. */
function liveBoutOn(WeightCategory $category): Bout
{
    $championship = $category->ageCategory->championship;

    $court = $championship->courts()->where('is_active', true)->first()
        ?? Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true]);

    $bout = readyBout($category);
    $bout->update(['court_id' => $court->id, 'status' => Bout::STATUS_ON_COURT]);

    return $bout->refresh();
}

/** Decide a single contest. */
function decideOne(WeightCategory $category): Bout
{
    $bout = readyBout($category);

    return app(BoutAdvancer::class)->recordResult(
        bout: $bout,
        winnerAthleteId: $bout->athlete_a_id,
        user: null,
    );
}

/** Fight the whole championship out, round by round as winners advance. */
function decideEverything(Championship $championship): void
{
    // Bounded rather than while(true): a linking bug in the generator would
    // otherwise hang the suite instead of failing it.
    for ($pass = 0; $pass < 40; $pass++) {
        $bout = $championship->bouts()->readyToFight()->orderBy('round')->orderBy('id')->first();

        if ($bout === null) {
            return;
        }

        app(BoutAdvancer::class)->recordResult(
            bout: $bout,
            winnerAthleteId: $bout->athlete_a_id,
            user: null,
        );
    }
}
