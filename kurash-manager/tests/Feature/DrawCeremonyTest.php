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
