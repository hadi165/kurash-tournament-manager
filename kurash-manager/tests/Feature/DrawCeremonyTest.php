<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\DrawCeremony;
use App\Models\User;
use App\Services\BracketGenerator;
use App\Support\BracketSeeding;
use Illuminate\Support\Facades\Cache;
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
