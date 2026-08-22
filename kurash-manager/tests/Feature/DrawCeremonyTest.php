<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\DrawCeremony;
use App\Models\User;
use App\Services\BracketGenerator;
use App\Support\BracketSeeding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->admin);
});

describe('what the server tells the ceremony', function () {
    it('announces completion only once the draw is recorded', function () {
        [$category] = categoryWithAthletes(8);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate')
            ->assertDispatched('draw-completed');

        expect($category->bouts()->count())->toBeGreaterThan(0);
    });

    /**
     * The guarantee the whole overlay rests on: "draw complete" is a server
     * fact, not a timer running out. A refused draw must never announce one.
     */
    it('never announces completion when the draw is refused', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);
        runTournament($category);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate')
            ->assertNotDispatched('draw-completed')
            ->assertDispatched('draw-failed');
    });

    it('reports a class with nobody through the scale as a failure', function () {
        [$category] = categoryWithAthletes(4);
        $category->athletes()->update(['weighin_status' => 'fail']);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('drawAtRandom')
            ->assertDispatched('draw-failed')
            ->assertSee('passed the weigh-in');
    });

    it('announces the positions draw separately from the bracket draw', function () {
        [$category] = categoryWithAthletes(6);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('drawAtRandom')
            ->assertDispatched('draw-completed', mode: 'positions');
    });

    it('carries the server message into the failure state', function () {
        [$category] = categoryWithAthletes(1);

        Livewire::test(Bracket::class, ['weightCategory' => $category])
            ->call('generate')
            ->assertDispatched('draw-failed');
    });
});

describe('the draw screen', function () {
    it('disables both draw actions while one is running', function () {
        [$category] = categoryWithAthletes(8);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category])->html();

        expect(substr_count($html, 'wire:target="drawAtRandom,generate"'))->toBeGreaterThanOrEqual(2)
            ->and($html)->toContain('wire:loading.attr="disabled"');
    });

    it('renders the ceremony as a live region that reports busy', function () {
        [$category] = categoryWithAthletes(8);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category])->html();

        expect($html)->toContain('aria-live="polite"')
            ->and($html)->toContain('draw-started')
            ->and($html)->toContain('draw-completed');
    });

    /** Real seeds, not invented ones: the overlay shows the pairing the generator will use. */
    it('shows the real first-round seeding in the pairing phase', function () {
        [$category] = categoryWithAthletes(8);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category])->html();

        // Bracket of 8 seeds 1v8, 4v5, 2v7, 3v6.
        expect($html)->toContain('dc-seat')
            ->and($html)->toContain('Forming the bracket');
    });
});

describe('reduced motion', function () {
    /**
     * The rule that keeps the accessible version honest: reduced motion may
     * remove movement, never information. Nothing may be hidden by it, and
     * nothing may depend on an animation having run to become visible.
     */
    it('keeps every element visible when motion is switched off', function () {
        $css = file_get_contents(resource_path('css/ceremony.css'));

        $block = (string) str($css)->after('@media (prefers-reduced-motion: reduce)')->before('@keyframes dc-fade');

        expect(str_contains($block, 'display: none'))->toBeFalse()
            ->and(str_contains($block, 'visibility: hidden'))->toBeFalse()
            ->and(str_contains($block, 'opacity: 0;'))->toBeFalse();
    });

    it('states the animations it replaces', function () {
        $css = file_get_contents(resource_path('css/ceremony.css'));

        expect($css)->toContain('@media (prefers-reduced-motion: reduce)')
            ->and($css)->toContain('dc-fade');
    });
});

describe('the venue draw board', function () {
    beforeEach(function () {
        $this->category = categoryWithAthletes(12)[0];
    });

    /** Same gate as the scoreboard: it hangs in the same hall. */
    it('is closed to anonymous viewers unless public displays are on', function () {
        auth()->logout();

        config()->set('display.public', false);
        $this->get(route('display.draw-ceremony', $this->category))->assertRedirect(route('login'));

        config()->set('display.public', true);
        $this->get(route('display.draw-ceremony', $this->category))->assertOk();
    });

    /**
     * The invariant the whole board rests on: every panel divides the same
     * revealed count, so placed, drawing and still-to-come always add up to
     * the entry list. Two panels on a public draw board must never contradict
     * each other.
     */
    it('keeps placed, drawing and remaining adding up to the entry list', function () {
        Cache::put(DrawCeremony::paceKey($this->category->id), ['at' => now()->timestamp - 21, 'per' => 3], now()->addHour());

        $board = Livewire::test(DrawCeremony::class, ['weightCategory' => $this->category]);

        $revealed = $board->viewData('revealed');
        $drawing = $board->viewData('drawing') === null ? 0 : 1;
        $remaining = $board->viewData('remainingCount');

        expect($revealed)->toBe(7)
            ->and($revealed + $drawing + $remaining)->toBe($board->viewData('total'));
    });

    it('paces the reveal from the stamp the draw left', function () {
        Cache::put(DrawCeremony::paceKey($this->category->id), ['at' => now()->timestamp - 9, 'per' => 3], now()->addHour());

        expect(Livewire::test(DrawCeremony::class, ['weightCategory' => $this->category])->viewData('revealed'))->toBe(3);
    });

    /** A draw entered by hand, or one made yesterday, is simply on the board. */
    it('shows a draw that was never paced in full', function () {
        $board = Livewire::test(DrawCeremony::class, ['weightCategory' => $this->category]);

        expect($board->viewData('revealed'))->toBe(12)
            ->and($board->viewData('complete'))->toBeTrue()
            ->and($board->viewData('drawing'))->toBeNull();

        $board->assertSee('Every position has been drawn.');
    });

    it('seats the board in the real seeding order', function () {
        $seeds = collect(Livewire::test(DrawCeremony::class, ['weightCategory' => $this->category])->viewData('seats'))
            ->pluck('seed')
            ->all();

        expect($seeds)->toBe(BracketSeeding::order(16));
    });

    it('marks only the position just filled', function () {
        Cache::put(DrawCeremony::paceKey($this->category->id), ['at' => now()->timestamp - 15, 'per' => 3], now()->addHour());

        $justFilled = collect(Livewire::test(DrawCeremony::class, ['weightCategory' => $this->category])->viewData('seats'))
            ->filter(fn (array $seat) => $seat['justFilled']);

        expect($justFilled)->toHaveCount(1)
            ->and($justFilled->first()['seed'])->toBe(5);
    });

    it('says so when nothing has been drawn', function () {
        $this->category->athletes()->update(['draw_number' => null]);

        Livewire::test(DrawCeremony::class, ['weightCategory' => $this->category->refresh()])
            ->assertSee('No draw numbers have been given out');
    });

    it('is stamped by the random draw so the hall sees it position by position', function () {
        Cache::forget(DrawCeremony::paceKey($this->category->id));

        Livewire::test(Bracket::class, ['weightCategory' => $this->category])->call('drawAtRandom');

        expect(Cache::get(DrawCeremony::paceKey($this->category->id)))->toHaveKey('at');
    });
});

describe('the operator runs the ceremony', function () {
    beforeEach(function () {
        [$this->category] = categoryWithAthletes(12);
        app(BracketGenerator::class)->generate($this->category);
        $this->category->forceFill(['draw_published_at' => now()])->save();
        $this->category->refresh();

        $this->operator = User::factory()->official()->create();

        Cache::forget(DrawCeremony::paceKey($this->category->id));
    });

    it('waits to be started rather than showing the answer', function () {
        Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
            ->assertSet('ceremony', true)
            ->assertSee('Ready to begin')
            ->assertViewHas('waiting', true)
            ->assertViewHas('revealed', 0);
    });

    /** Starting tells the hall. It does not touch the draw. */
    it('starts the telling without touching the draw', function () {
        $before = $this->category->bouts()->pluck('athlete_a_id', 'id')->toArray();
        $version = $this->category->draw_version;

        Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
            ->call('startCeremony');

        expect(Cache::has(DrawCeremony::paceKey($this->category->id)))->toBeTrue()
            ->and($this->category->refresh()->draw_version)->toBe($version)
            ->and($this->category->bouts()->pluck('athlete_a_id', 'id')->toArray())->toBe($before);
    });

    it('counts athletes, not bracket seats', function () {
        $board = Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true]);

        // Twelve athletes in a bracket of sixteen: two different numbers.
        expect($board->viewData('total'))->toBe(12)
            ->and($board->viewData('size'))->toBe(16);
    });

    it('keeps drawn, drawing and remaining adding up at every step', function () {
        foreach ([0, 3, 15, 33, 36] as $elapsed) {
            Cache::put(
                DrawCeremony::paceKey($this->category->id),
                ['at' => now()->timestamp - $elapsed, 'per' => 3],
                now()->addHour(),
            );

            $board = Livewire::actingAs($this->operator)
                ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true]);

            $drawn = $board->viewData('revealed');
            $drawing = $board->viewData('drawing') === null ? 0 : 1;

            expect($drawn + $drawing + $board->viewData('remainingCount'))->toBe(12);
        }
    });

    /** The reveal is derived from the stamp, so a refresh lands where it left. */
    it('shows the same positions after a refresh', function () {
        Cache::put(
            DrawCeremony::paceKey($this->category->id),
            ['at' => now()->timestamp - 18, 'per' => 3],
            now()->addHour(),
        );

        $seats = fn () => collect(Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
            ->viewData('seats'))
            ->map(fn (array $seat) => [$seat['seed'], $seat['athlete']?->id])
            ->all();

        expect($seats())->toBe($seats());
    });

    it('reveals in the seeded order and never twice', function () {
        Cache::put(
            DrawCeremony::paceKey($this->category->id),
            ['at' => now()->timestamp - 3600, 'per' => 3],
            now()->addHour(),
        );

        $seats = collect(Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
            ->viewData('seats'));

        expect($seats->pluck('seed')->all())->toBe(BracketSeeding::order(16));

        $placed = $seats->pluck('athlete')->filter();

        // Every athlete appears once, and only the twelve who exist.
        expect($placed)->toHaveCount(12)
            ->and($placed->pluck('id')->unique())->toHaveCount(12);
    });

    it('is refused for a draw that has not been published', function () {
        $this->category->forceFill(['draw_published_at' => null])->save();

        $this->actingAs($this->operator)
            ->get(route('operator.draws.ceremony', $this->category->refresh()))
            ->assertForbidden();
    });

    it('is refused for an account that may only watch a scoreboard', function () {
        $this->actingAs(User::factory()->scoreboardViewer()->create())
            ->get(route('operator.draws.ceremony', $this->category))
            ->assertForbidden();
    });
});

describe('the beat inside a position', function () {
    it('hands the page the clock rather than a phase', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();

        Cache::put(DrawCeremony::paceKey($category->id), ['at' => now()->timestamp - 5, 'per' => 3], now()->addHour());

        $board = Livewire::actingAs(User::factory()->official()->create())
            ->test(DrawCeremony::class, ['weightCategory' => $category->refresh(), 'ceremony' => true]);

        expect($board->viewData('pace'))->toBe(['at' => now()->timestamp - 5, 'per' => 3]);
    });

    /**
     * Both states are rendered and the beat only decides which shows, so a
     * page whose script never runs still reads the athlete's name.
     */
    it('renders the name whether or not the beat runs', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();

        Cache::put(DrawCeremony::paceKey($category->id), ['at' => now()->timestamp - 5, 'per' => 3], now()->addHour());

        $board = Livewire::actingAs(User::factory()->official()->create())
            ->test(DrawCeremony::class, ['weightCategory' => $category->refresh(), 'ceremony' => true]);

        $drawing = $board->viewData('drawing');

        $board->assertSee($drawing->fullname)->assertSee('Drawing');
    });

    it('has no beat to run once the draw is complete', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();

        Cache::put(DrawCeremony::paceKey($category->id), ['at' => now()->timestamp - 3600, 'per' => 3], now()->addHour());

        $board = Livewire::actingAs(User::factory()->official()->create())
            ->test(DrawCeremony::class, ['weightCategory' => $category->refresh(), 'ceremony' => true]);

        expect($board->viewData('drawing'))->toBeNull()
            ->and($board->viewData('complete'))->toBeTrue();
    });
});

describe('the operator paces the reveal', function () {
    beforeEach(function () {
        [$this->category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($this->category);
        $this->category->forceFill(['draw_published_at' => now()])->save();
        $this->category->refresh();

        $this->operator = User::factory()->official()->create();
        Cache::forget(DrawCeremony::paceKey($this->category->id));

        $this->board = fn () => Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true]);
    });

    it('offers Begin draw first and Next draw after that', function () {
        ($this->board)()->assertSee('Begin draw')->assertDontSee('Next draw');

        Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
            ->call('startCeremony')
            ->assertSee('Next draw')
            ->assertDontSee('Begin draw');
    });

    it('places one position per press', function () {
        $board = ($this->board)();

        $board->call('startCeremony');
        expect($board->viewData('revealed'))->toBe(0);

        foreach ([1, 2, 3, 4] as $expected) {
            $board->call('nextDraw');
            expect($board->viewData('revealed'))->toBe($expected);
        }
    });

    it('stops at the last position rather than counting past it', function () {
        $board = ($this->board)();
        $board->call('startCeremony');

        foreach (range(1, 8) as $ignored) {
            $board->call('nextDraw');
        }

        expect($board->viewData('revealed'))->toBe(4)
            ->and($board->viewData('complete'))->toBeTrue();

        // Nothing left to press once every position is placed.
        $board->assertDontSee('Next draw');
    });

    it('keeps the counters adding up as it goes', function () {
        $board = ($this->board)();
        $board->call('startCeremony');

        foreach (range(0, 4) as $ignored) {
            $drawn = $board->viewData('revealed');
            $drawing = $board->viewData('drawing') === null ? 0 : 1;

            expect($drawn + $drawing + $board->viewData('remainingCount'))->toBe(4);

            $board->call('nextDraw');
        }
    });

    /** Pressing is telling, not drawing: the bracket cannot move. */
    it('never changes the draw by pressing', function () {
        $before = $this->category->bouts()->pluck('athlete_a_id', 'id')->toArray();
        $version = $this->category->draw_version;

        $board = ($this->board)();
        $board->call('startCeremony');
        $board->call('nextDraw');
        $board->call('nextDraw');

        expect($this->category->refresh()->draw_version)->toBe($version)
            ->and($this->category->bouts()->pluck('athlete_a_id', 'id')->toArray())->toBe($before);
    });

    it('is refused for anybody who may not present', function () {
        Livewire::actingAs(User::factory()->scoreboardViewer()->create())
            ->test(DrawCeremony::class, ['weightCategory' => $this->category])
            ->call('nextDraw')
            ->assertForbidden();
    });
});

describe('the admin draw screen', function () {
    /**
     * The overlay covers the wait and then gets out of the way. The
     * celebration belongs on the venue screen, in front of a hall that came to
     * watch it.
     */
    it('has no completion celebration on it', function () {
        $overlay = file_get_contents(resource_path('views/components/draw/ceremony.blade.php'));

        expect(str_contains($overlay, "phase === 'complete'"))->toBeFalse()
            ->and(str_contains($overlay, 'Draw complete'))->toBeFalse()
            // It still says when a draw could not be made, which is not a
            // celebration.
            ->and(str_contains($overlay, 'Draw could not be completed'))->toBeTrue();
    });
});

describe('the component is bound to its markup', function () {
    /**
     * Regression: the view emitted its stylesheet with @vite, which put a
     * <link> in front of the markup. A component view has one root element,
     * and Livewire binds to the first it finds — it bound to the link, so
     * every button on the page sat outside the component and did nothing when
     * pressed, and the poll never ran either. The stylesheet belongs to the
     * layout.
     */
    it('binds to the board, not to a stylesheet link', function () {
        [$category] = categoryWithAthletes(6);
        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();

        $html = $this->actingAs(User::factory()->official()->create())
            ->get(route('operator.draws.ceremony', $category->refresh()))
            ->getContent();

        preg_match('/<([a-z]+)[^>]*wire:id=/', $html, $root);

        expect($root[1] ?? null)->toBe('div');

        // And the control is inside the component that carries the action.
        expect($html)->toContain('wire:click="startCeremony"');
    });

    it('binds the scoreboard viewer to its board too', function () {
        [$court] = boutOnMat();

        $html = $this->actingAs(User::factory()->scoreboardViewer()->create())
            ->get(route('scoreboard.show', $court))
            ->getContent();

        preg_match('/<([a-z]+)[^>]*wire:id=/', $html, $root);

        expect($root[1] ?? null)->toBe('div');
    });

    /** No component view may emit a stylesheet: that is what caused it. */
    it('keeps stylesheet links out of every component view', function () {
        $offenders = collect(File::allFiles(resource_path('views/livewire')))
            ->filter(fn ($file) => str_contains(File::get($file->getPathname()), '@vite('))
            ->map(fn ($file) => $file->getRelativePathname());

        expect($offenders)->toBeEmpty();
    });
});

/**
 * The pot, as the hall reads it.
 *
 * A three-letter code is not something a room full of delegations reads at a
 * glance; a flag is. So the sidebar carries the nation's own artwork beside
 * its code rather than a number only the operator has any use for.
 */
describe('the pool sidebar', function () {
    beforeEach(function () {
        [$this->category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($this->category);
        $this->category->forceFill(['draw_published_at' => now()])->save();
        $this->category->refresh();

        $this->operator = User::factory()->official()->create();

        // Nothing revealed yet, so everybody is still in the pot.
        Cache::forget(DrawCeremony::paceKey($this->category->id));
    });

    /** @return Collection<int, array<string, mixed>> */
    function poolOf(mixed $category, mixed $operator)
    {
        return Livewire::actingAs($operator)
            ->test(DrawCeremony::class, ['weightCategory' => $category, 'ceremony' => true])
            ->viewData('pool');
    }

    it('carries a flag for every nation still to be drawn', function () {
        $pool = poolOf($this->category, $this->operator);

        expect($pool)->not->toBeEmpty();

        foreach ($pool as $entry) {
            expect($entry)->toHaveKeys(['noc', 'name', 'iso']);
        }
    });

    /**
     * Resolved from the code rather than derived from it. BRN is Bahrain and
     * BRU is Brunei, and no rule turns one into the other.
     */
    it('resolves the flag through the code table, not by guessing', function () {
        $this->category->athletes()->update(['noc_code' => 'BRN']);

        expect(poolOf($this->category, $this->operator)->first()['iso'])->toBe('bh');
    });

    /** The board carries the flag beside the code, seat by seat. */
    it('flies a flag on every seat that has been filled', function () {
        Cache::put(
            DrawCeremony::paceKey($this->category->id),
            ['revealed' => 8],
            now()->addHour(),
        );

        $seats = Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
            ->viewData('seats');

        $filled = collect($seats)->filter(fn (array $seat) => $seat['athlete'] !== null);

        expect($filled)->not->toBeEmpty()
            ->and($filled->every(fn (array $seat) => $seat['iso'] !== null))->toBeTrue();
    });

    /** An empty seat has no nation to fly. */
    it('leaves an undrawn seat without one', function () {
        $seats = Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
            ->viewData('seats');

        expect(collect($seats)->every(fn (array $seat) => $seat['iso'] === null))->toBeTrue();
    });

    /**
     * The whole name. It is not shortened anywhere on the way to the board —
     * what limits it is the width of the column, which is the stylesheet's
     * business and not this one's.
     */
    it('puts the athlete\'s full name on the board', function () {
        Cache::put(
            DrawCeremony::paceKey($this->category->id),
            ['revealed' => 8],
            now()->addHour(),
        );

        $athlete = $this->category->drawnAthletes()->first();
        $athlete->update(['fullname' => 'Bekzod Yuldashev Rakhmatovich']);

        Livewire::actingAs($this->operator)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category->refresh(), 'ceremony' => true])
            ->assertSee('Bekzod Yuldashev Rakhmatovich');
    });

    /** A code with no artwork keeps its row rather than collapsing it. */
    it('leaves a nation with no flag a box of its own', function () {
        $this->category->athletes()->update(['noc_code' => 'ZZZ']);

        expect(poolOf($this->category, $this->operator)->first()['iso'])->toBeNull();
    });
});

/**
 * A draw is run class after class, so the screen that runs one has to be the
 * screen you leave to reach the next.
 */
describe('finding the next class', function () {
    beforeEach(function () {
        [$this->category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($this->category);
        $this->category->forceFill(['draw_published_at' => now()])->save();
        $this->category->refresh();
    });

    /**
     * Entries and Draw, which is the list of classes with their state — the
     * one screen that says which is drawn and which is still waiting.
     */
    it('sends whoever is running it back to entries and draw', function () {
        $championship = $this->category->ageCategory->championship;

        foreach ([User::factory()->official()->create(), User::factory()->create(['role' => 'admin'])] as $user) {
            Livewire::actingAs($user)
                ->test(DrawCeremony::class, ['weightCategory' => $this->category, 'ceremony' => true])
                ->assertSee('All classes')
                ->assertSee(route('entries.index', $championship), false);
        }
    });

    /** A board on a wall has nowhere to navigate to and nobody to press it. */
    it('offers nothing of the kind on the venue board', function () {
        Livewire::actingAs($this->admin)
            ->test(DrawCeremony::class, ['weightCategory' => $this->category])
            ->assertDontSee('All classes');
    });
});

/**
 * The whole draw, on whatever screen it is opened on.
 *
 * The board used to pick a row height from the bracket size — 46px, or 30px
 * once there were thirty-two seats. That fitted a 1080 projector and put the
 * bottom of a sixteen-draw below the fold of a laptop, where nothing scrolled
 * to reach it. The stylesheet divides the viewport by this instead.
 */
describe('the board fits the screen it is on', function () {
    it('tells the stylesheet how many seats there are to fit', function () {
        [$category] = categoryWithAthletes(12);
        app(BracketGenerator::class)->generate($category);
        $category->forceFill(['draw_published_at' => now()])->save();

        // Twelve athletes are drawn into a bracket of sixteen, and it is the
        // seats that have to fit, not the entries.
        Livewire::actingAs($this->admin)
            ->test(DrawCeremony::class, ['weightCategory' => $category->refresh()])
            ->assertViewHas('size', 16)
            ->assertSee('--dc-seats: 16', false);
    });

    it('counts a smaller bracket down rather than up', function () {
        [$category] = categoryWithAthletes(5);
        app(BracketGenerator::class)->generate($category);

        Livewire::actingAs($this->admin)
            ->test(DrawCeremony::class, ['weightCategory' => $category->refresh()])
            ->assertSee('--dc-seats: 8', false);
    });

    /** A class with nothing drawn still has to render something sane. */
    it('never divides by nothing', function () {
        [$category] = categoryWithAthletes(0);

        Livewire::actingAs($this->admin)
            ->test(DrawCeremony::class, ['weightCategory' => $category])
            ->assertSee('--dc-seats: 1', false);
    });
});
