<?php

/**
 * The operator's primary "Present draw" action.
 *
 * These go through the route the button actually opens —
 * `operator.draws.present` — and not through a component the button does not
 * reach. That distinction is the whole reason the defect survived: the draw
 * *table* page had been taught about round robins, the presentation had not,
 * and every existing round-robin test exercised the table.
 *
 * What is asserted here is the dispatch: a draw is presented as the format it
 * was generated as, read off the stored snapshot, whatever the entry list has
 * done since.
 */

use App\Livewire\Competition\DrawCeremony;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\DrawGenerator;
use App\Services\FightOrderScheduler;
use App\Support\TournamentFormat;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->operator = User::factory()->create(['role' => 'official']);
});

/**
 * A published class, drawn in whatever format its field calls for.
 *
 * Women by default and labelled the way the reproduction describes it, so the
 * two-athlete case here is the one that was reported.
 */
function presentable(int $athletes, ?TournamentFormat $format = null, string $label = '+70', string $gender = 'F'): WeightCategory
{
    // Unique but short: the label column holds sixteen characters, and a
    // class named past that fails to insert rather than failing an assertion.
    static $serial = 0;
    $serial++;

    $ageCategory = AgeCategory::factory()->create(['gender' => $gender]);

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => $label.$athletes.'x'.$serial,
        'gender' => $gender,
    ]);

    foreach (range(1, $athletes) as $draw) {
        Athlete::factory()->drawn($draw)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'fullname' => "Presented Athlete {$draw}",
            'weighin_status' => 'pass',
        ]);
    }

    app(DrawGenerator::class)->generate(
        $category->refresh(),
        $format,
        overrideReason: $format === TournamentFormat::Knockout && $athletes <= 5 ? 'Local rules.' : null,
        user: User::where('role', 'admin')->first(),
    );

    $category->refresh()->forceFill(['draw_published_at' => now()])->save();

    return $category->refresh();
}

/** The page the Present button actually opens. */
function present(WeightCategory $category)
{
    return test()->get(route('operator.draws.present', $category));
}

describe('the reported defect: a two-athlete round robin', function () {
    it('presents the round robin rather than a bracket', function () {
        $category = presentable(2);

        expect($category->drawFormat())->toBe(TournamentFormat::RoundRobin);

        $this->actingAs($this->operator);

        present($category)
            ->assertOk()
            ->assertSee('Round Robin')
            ->assertSee('2 athletes')
            ->assertSee('1 round')
            ->assertSee('1 contest');
    });

    it('waits to be started rather than telling itself', function () {
        $category = presentable(2);

        $this->actingAs($this->operator);

        present($category)
            ->assertOk()
            ->assertSee('Start presentation')
            // Nothing is revealed until somebody presses.
            ->assertSee('To be drawn');
    });

    /** None of the knockout's vocabulary belongs on this board. */
    it('shows nothing of a bracket', function () {
        $category = presentable(2);

        $this->actingAs($this->operator);

        $html = present($category)->assertOk()->getContent();

        // '>Champion<' and not the bare word, which would also match the
        // championship's own title in the page header.
        foreach (['Bracket of', 'BYE', 'Quarter Final', 'Semi Final', '>Champion<', 'Champion path', 'dc-match', 'dc-champion', 'dc-seat-row'] as $forbidden) {
            expect($html)->not->toContain($forbidden);
        }
    });

    it('shows the persisted pairing and its fight number once told', function () {
        $category = presentable(2);
        app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);

        $bout = $category->bouts()->first();

        expect($bout->fight_number)->not->toBeNull();

        // The telling, finished: both athletes placed.
        Cache::put(
            DrawCeremony::paceKey($category->id, (int) $category->draw_version),
            ['revealed' => 2],
            now()->addHour(),
        );

        $this->actingAs($this->operator);

        present($category)
            ->assertOk()
            ->assertSee('No. '.$bout->fight_number)
            ->assertSee($bout->athleteA->fullname)
            ->assertSee($bout->athleteB->fullname);
    });
});

describe('the other round-robin fields', function () {
    it('shows every generated pairing exactly once', function (int $athletes, int $contests, int $rounds) {
        $category = presentable($athletes);

        Cache::put(
            DrawCeremony::paceKey($category->id, (int) $category->draw_version),
            ['revealed' => $athletes],
            now()->addHour(),
        );

        $this->actingAs($this->operator);

        $html = present($category)->assertOk()->getContent();

        // One card per contest, and the rounds the generator actually wrote.
        // Counted by wire:key, which appears exactly once per card — the CSS
        // class also appears inside its own modifiers, so it cannot be
        // counted without knowing which states the cards are in.
        expect(substr_count($html, 'wire:key="rr-fixture-'))->toBe($contests)
            ->and(substr_count($html, 'dc-rr-round'))->toBeGreaterThanOrEqual($rounds);

        foreach ($category->bouts()->get() as $bout) {
            expect($html)->toContain('wire:key="rr-fixture-'.$bout->id.'"');
        }
    })->with([
        'three' => [3, 3, 3],
        'four' => [4, 6, 3],
        'five' => [5, 10, 5],
    ]);

    /** A rest is not a contest, and must never appear as one. */
    it('does not show an odd field a rest position as a bye', function () {
        $category = presentable(5);

        Cache::put(
            DrawCeremony::paceKey($category->id, (int) $category->draw_version),
            ['revealed' => 5],
            now()->addHour(),
        );

        $this->actingAs($this->operator);

        $html = present($category)->assertOk()->getContent();

        expect($category->bouts()->count())->toBe(10)
            ->and($category->bouts()->where('is_bye', true)->count())->toBe(0)
            // Ten fixtures, not fifteen: the five rests are nowhere.
            ->and(substr_count($html, 'wire:key="rr-fixture-'))->toBe(10)
            ->and($html)->not->toContain('BYE');
    });
});

describe('the presentation writes nothing', function () {
    /**
     * The guarantee the whole screen rests on: the draw was committed before
     * this page existed, and telling it again cannot alter a single row.
     */
    it('leaves the draw untouched however it is driven', function () {
        $category = presentable(4);
        app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);

        $before = [
            'version' => (int) $category->refresh()->draw_version,
            'bouts' => $category->bouts()->orderBy('id')->get()
                ->map(fn (Bout $b) => [$b->id, $b->athlete_a_id, $b->athlete_b_id, $b->fight_number, $b->winner_athlete_id])
                ->all(),
            'draws' => $category->athletes()->orderBy('id')->pluck('draw_number')->all(),
        ];

        $component = Livewire::actingAs($this->operator)->test(DrawCeremony::class, [
            'weightCategory' => $category,
            'ceremony' => true,
            'automatic' => true,
        ]);

        $component->call('startCeremony');

        Cache::put(
            DrawCeremony::paceKey($category->id, (int) $category->draw_version),
            ['revealed' => 4],
            now()->addHour(),
        );

        $component->call('saveDraw')->call('nextDraw');

        $category->refresh();

        expect((int) $category->draw_version)->toBe($before['version'])
            ->and($category->bouts()->orderBy('id')->get()
                ->map(fn (Bout $b) => [$b->id, $b->athlete_a_id, $b->athlete_b_id, $b->fight_number, $b->winner_athlete_id])
                ->all())->toBe($before['bouts'])
            ->and($category->athletes()->orderBy('id')->pluck('draw_number')->all())->toBe($before['draws']);
    });

    /**
     * A redrawn class is a different draw. A half-told reveal of the old one
     * must not open the new one part-finished — which is what would happen if
     * presentation state were kept per class rather than per draw.
     */
    it('cannot inherit the telling of a draw that has been replaced', function () {
        $category = presentable(8, TournamentFormat::Knockout, '-90', 'M');

        $knockoutVersion = (int) $category->draw_version;

        // A knockout presentation, most of the way through.
        Cache::put(
            DrawCeremony::paceKey($category->id, $knockoutVersion),
            ['revealed' => 7],
            now()->addHour(),
        );

        // The class shrinks and is redrawn as a round robin.
        $category->athletes()->orderByDesc('draw_number')->limit(6)->get()
            ->each(fn (Athlete $a) => $a->forceFill(['draw_number' => null])->save());

        $category->forceFill(['draw_published_at' => null])->save();

        app(DrawGenerator::class)->generate($category->refresh());
        $category->refresh()->forceFill(['draw_published_at' => now()])->save();

        expect($category->refresh()->drawFormat())->toBe(TournamentFormat::RoundRobin)
            ->and((int) $category->draw_version)->toBeGreaterThan($knockoutVersion);

        $this->actingAs($this->operator);

        // Waiting, not seven-eighths told.
        present($category)
            ->assertOk()
            ->assertSee('Round Robin')
            ->assertSee('Start presentation');
    });
});

describe('the knockout, which must not have moved', function () {
    it('still presents a small explicit knockout as a bracket', function () {
        $category = presentable(2, TournamentFormat::Knockout);

        expect($category->drawFormat())->toBe(TournamentFormat::Knockout);

        $this->actingAs($this->operator);

        present($category)
            ->assertOk()
            ->assertSee('Bracket of 2')
            ->assertSee('dc-seat-row', false)
            ->assertDontSee('Round Robin');
    });

    it('still presents a large knockout exactly as before', function () {
        $category = presentable(8, null, '-90', 'M');

        $this->actingAs($this->operator);

        present($category)
            ->assertOk()
            ->assertSee('Bracket of 8')
            ->assertSee('dc-seat-row', false)
            ->assertSee('Champion')
            ->assertSee('Start presentation');
    });

    /** Everything drawn before formats existed is a bracket and stays one. */
    it('presents a historical unstamped draw as a knockout', function () {
        $category = presentable(3, TournamentFormat::Knockout, '-60', 'M');

        WeightCategory::whereKey($category->id)
            ->update(['draw_format' => null, 'draw_format_preference' => null]);

        $this->actingAs($this->operator);

        present($category->refresh())
            ->assertOk()
            ->assertSee('Bracket of 4')
            ->assertDontSee('Round Robin');
    });
});

describe('the class of one', function () {
    it('presents an administrative placement rather than an empty bracket', function () {
        $category = presentable(1);

        expect($category->drawFormat())->toBe(TournamentFormat::Placement)
            ->and($category->bouts()->count())->toBe(0);

        $this->actingAs($this->operator);

        present($category)
            ->assertOk()
            ->assertSee('Single entrant')
            ->assertDontSee('Bracket of')
            ->assertDontSee('Round Robin');
    });
});

describe('a draw that has moved under the presentation', function () {
    /**
     * The snapshot is the authority. A sixth athlete registering while the
     * hall is watching does not turn a published round robin into a bracket.
     */
    it('keeps presenting the format the draw was generated as', function () {
        $category = presentable(4);

        Athlete::factory()->drawn(5)->create([
            'championship_id' => $category->ageCategory->championship_id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
            'fullname' => 'Late Entry',
            'weighin_status' => 'pass',
        ]);

        Athlete::factory()->drawn(6)->create([
            'championship_id' => $category->ageCategory->championship_id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
            'fullname' => 'Later Entry',
            'weighin_status' => 'pass',
        ]);

        // The rule would now give this field a bracket; the draw on the table
        // is still the round robin the hall was told about.
        expect($category->refresh()->resolvedFormat())->toBe(TournamentFormat::Knockout)
            ->and($category->drawFormat())->toBe(TournamentFormat::RoundRobin);

        $this->actingAs($this->operator);

        present($category)
            ->assertOk()
            ->assertSee('Round Robin')
            ->assertDontSee('Bracket of');
    });
});

describe('who may present what', function () {
    it('refuses an unpublished draw', function () {
        $category = presentable(4);
        $category->forceFill(['draw_published_at' => null])->save();

        $this->actingAs($this->operator);

        present($category->refresh())->assertForbidden();
    });

    it('refuses an account confined to a mat', function () {
        $category = presentable(4);

        $this->actingAs(User::factory()->create(['role' => 'scoreboard_viewer']));

        present($category)->assertForbidden();
    });

    it('refuses an archived championship', function () {
        $category = presentable(4);
        $category->ageCategory->championship->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->operator);

        present($category->refresh())->assertForbidden();
    });
});

describe('the operator draw list', function () {
    it('describes a round robin in its own terms', function () {
        $category = presentable(5);

        $this->actingAs($this->operator);

        $html = $this->get(route('operator.draws.index'))->assertOk()->getContent();

        expect($html)->toContain('Round Robin')
            ->toContain('5 athletes')
            ->toContain('5 rounds')
            ->toContain('10 contests')
            // None of the bracket's arithmetic applies to it.
            ->not->toContain('bracket of')
            ->and($html)->not->toContain('no byes');
    });

    it('keeps the bracket figures for a bracket', function () {
        $category = presentable(8, null, '-90', 'M');

        $this->actingAs($this->operator);

        $this->get(route('operator.draws.index'))
            ->assertOk()
            ->assertSee('Knockout')
            ->assertSee('bracket of 8')
            ->assertSee('7 bouts');
    });

    /** A class of one has no contests, and used to be unpresentable for it. */
    it('offers a class of one its presentation', function () {
        $category = presentable(1);

        $this->actingAs($this->operator);

        $this->get(route('operator.draws.index'))
            ->assertOk()
            ->assertSee('Administrative placement')
            ->assertSee(route('operator.draws.present', $category), false);
    });
});

describe('the finished presentation', function () {
    it('names the round-robin documents for what they are', function () {
        $category = presentable(4);

        Cache::put(
            DrawCeremony::paceKey($category->id, (int) $category->draw_version),
            ['revealed' => 4],
            now()->addHour(),
        );

        $component = Livewire::actingAs($this->operator)->test(DrawCeremony::class, [
            'weightCategory' => $category,
            'ceremony' => true,
            'automatic' => true,
        ]);

        $component->call('saveDraw')
            ->assertSee('Round Robin PDF')
            ->assertSee('Round Robin Excel')
            ->assertDontSee('Bracket PDF');
    });

    it('keeps the bracket document labels for a bracket', function () {
        $category = presentable(8, null, '-90', 'M');

        Cache::put(
            DrawCeremony::paceKey($category->id, (int) $category->draw_version),
            ['revealed' => 8],
            now()->addHour(),
        );

        Livewire::actingAs($this->operator)->test(DrawCeremony::class, [
            'weightCategory' => $category,
            'ceremony' => true,
            'automatic' => true,
        ])
            ->call('saveDraw')
            ->assertSee('Bracket PDF')
            ->assertSee('Bracket Excel')
            ->assertDontSee('Round Robin PDF');
    });

    /** The export endpoint routes itself; the labels only have to agree. */
    it('downloads the round-robin sheet from the same endpoint', function () {
        $category = presentable(4);

        $this->actingAs($this->admin);

        $pdf = $this->get(route('exports.bracket-sheet', [
            'weightCategory' => $category, 'format' => 'pdf', 'fights' => 0,
        ]));

        expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
    });
});

describe('the draw table page, which is the other door', function () {
    it('counts a round robin in rounds and contests', function () {
        $category = presentable(4);

        $this->actingAs($this->operator);

        $this->get(route('operator.draws.show', $category))
            ->assertOk()
            ->assertSee('Round Robin')
            ->assertSee('Contests')
            ->assertSee('Scheduled fights')
            ->assertDontSee('Bracket size')
            ->assertDontSee('First-round bouts');
    });

    it('keeps the bracket figures on a bracket', function () {
        $category = presentable(8, null, '-90', 'M');

        $this->actingAs($this->operator);

        $this->get(route('operator.draws.show', $category))
            ->assertOk()
            ->assertSee('Bracket size')
            ->assertSee('First-round bouts');
    });
});
