<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Brackets;
use App\Models\User;
use App\Services\BracketGenerator;
use App\Support\BracketSeeding;
use Livewire\Livewire;

/**
 * The federation's bracket standard, checked where it actually lands.
 *
 * BracketSeedingTest pins the tables. This checks that everything downstream
 * reads them — the generated bouts, the screen, the exports — because a
 * centralized standard that one component quietly ignores is not centralized.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);
});

describe('generated bouts', function () {
    /**
     * Seats are read off the table in order, so bout 0 of round 1 is the
     * table's first row and its blue athlete is the row's first seed.
     */
    it('seats the first round in the federation order', function (int $athletes) {
        [$category] = categoryWithAthletes($athletes);
        app(BracketGenerator::class)->generate($category);

        $size = BracketSeeding::size($athletes);

        $seated = $category->bouts()
            ->where('round', 1)
            ->orderBy('position_in_round')
            ->get()
            ->map(fn ($bout) => [$bout->seed_a, $bout->seed_b])
            ->all();

        expect($seated)->toBe(BracketSeeding::firstRoundPairs($size));
    })->with([2, 4, 8, 16, 32]);

    /**
     * The draw's whole purpose. If the table were transcribed wrongly the top
     * two could meet in a semi-final, and nothing else in the system would
     * notice.
     */
    it('keeps the top two seeds apart until the final', function (int $athletes) {
        [$category] = categoryWithAthletes($athletes);
        app(BracketGenerator::class)->generate($category);

        $first = $category->bouts()->where('round', 1)->orderBy('position_in_round')->get();
        $half = $first->count() / 2;

        $positionOf = fn (int $seed) => $first
            ->search(fn ($bout) => $bout->seed_a === $seed || $bout->seed_b === $seed);

        expect($positionOf(1) < $half)->not->toBe($positionOf(2) < $half);
    })->with([4, 8, 16, 32]);

    it('draws the level the field calls for rather than a fixed sixteen', function (int $athletes, int $size) {
        [$category] = categoryWithAthletes($athletes);
        app(BracketGenerator::class)->generate($category);

        expect($category->refresh()->draw_bucket_size)->toBe($size)
            ->and(BracketSeeding::level($athletes))->toBe("x/{$size}");
    })->with([
        [2, 2], [3, 4], [4, 4], [5, 8], [8, 8], [9, 16], [16, 16], [17, 32],
    ]);
});

describe('the bracket screen', function () {
    it('draws a connector for every progression in the tree', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category])->html();

        // The three connector pieces, and the round that must not draw a
        // trailing one because nothing follows it.
        expect($html)->toContain('bkt__slot')
            ->and($html)->toContain('bkt__match')
            ->and($html)->toContain('bkt__round--last');
    });

    it('gives every bout a slot of its own so the rounds stay aligned', function () {
        [$category] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        $html = Livewire::test(Bracket::class, ['weightCategory' => $category])->html();

        // 4 + 2 + 1 bouts in an eight-draw, and the champion the final feeds:
        // the tree does not stop at the final, so neither does the count.
        expect(substr_count($html, 'class="bkt__slot"'))->toBe(8);
    });
});

describe('the bracket index', function () {
    /** §22.3 — the draw is run from Entries, and two doors into it was one too many. */
    it('no longer offers Open', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Brackets::class, ['championship' => $category->ageCategory->championship])
            ->assertSee($category->exportName())
            ->assertDontSee('>'.__('Open').'<', escape: false);
    });

    it('names the bracket by its level', function () {
        [$category] = categoryWithAthletes(5);
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Brackets::class, ['championship' => $category->ageCategory->championship])
            ->assertSee('x/8');
    });
});
