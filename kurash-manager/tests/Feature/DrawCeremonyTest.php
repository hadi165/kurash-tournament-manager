<?php

use App\Livewire\Competition\Bracket;
use App\Models\User;
use App\Services\BracketGenerator;
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
