<?php

/**
 * Which tournament a class is run as, and what that produces.
 *
 * The IKA rule draws a field of two to five as a round robin and anything
 * larger as a knockout; a field of one has nothing to fight and is settled by
 * an administrative placement. These are about the resolution of that rule and
 * the two generators behind it — the standings that read the contests
 * afterwards are in RoundRobinStandingsTest.
 */

use App\Models\Bout;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BracketGenerator;
use App\Services\DrawFormatException;
use App\Services\DrawGenerator;
use App\Services\RoundRobinGenerator;
use App\Services\TournamentFormatPolicy;
use App\Support\BracketSeeding;
use App\Support\TournamentFormat;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->admin);
});

/** A class of `$count` drawn athletes, undrawn. */
function classOf(int $count, string $label = '-rr'): WeightCategory
{
    [$category] = categoryWithAthletes($count, $label."-{$count}");

    return $category;
}

describe('the format the rule gives a field', function () {
    it('offers what the IKA rule allows, best first', function (int $athletes, array $expected) {
        $available = array_map(
            fn (TournamentFormat $f) => $f->value,
            app(TournamentFormatPolicy::class)->availableFor($athletes),
        );

        expect($available)->toBe($expected);
    })->with([
        'nobody' => [0, []],
        'one — nothing to fight' => [1, ['placement']],
        'two' => [2, ['round_robin', 'knockout']],
        'three' => [3, ['round_robin', 'knockout']],
        'four' => [4, ['round_robin', 'knockout']],
        'five — the last small field' => [5, ['round_robin', 'knockout']],
        'six — a bracket from here on' => [6, ['knockout']],
        'sixteen' => [16, ['knockout']],
    ]);

    /** The head of the list is the default, so this is the same question. */
    it('defaults every small field to the round robin', function (int $athletes) {
        expect(app(TournamentFormatPolicy::class)->defaultFor($athletes))
            ->toBe(TournamentFormat::RoundRobin);
    })->with([2, 3, 4, 5]);

    it('never offers a round robin above five', function (int $athletes) {
        expect(app(TournamentFormatPolicy::class)->allows($athletes, TournamentFormat::RoundRobin))->toBeFalse()
            ->and(app(TournamentFormatPolicy::class)->defaultFor($athletes))->toBe(TournamentFormat::Knockout);
    })->with([6, 7, 8, 16, 32]);

    /**
     * The distinction the whole feature turns on: a small knockout is
     * permitted and is not compliant, and this system must never call it so.
     */
    it('calls a small knockout an override rather than the rule', function () {
        $policy = app(TournamentFormatPolicy::class);

        expect($policy->isOverride(4, TournamentFormat::Knockout))->toBeTrue()
            ->and(TournamentFormat::Knockout->followsIkaRule(4))->toBeFalse()
            // The same format in a field the rule gives it to is not an override.
            ->and($policy->isOverride(8, TournamentFormat::Knockout))->toBeFalse()
            ->and(TournamentFormat::Knockout->followsIkaRule(8))->toBeTrue()
            ->and($policy->isOverride(4, TournamentFormat::RoundRobin))->toBeFalse();
    });

    /**
     * A preference is a plan, not a promise. A class set to knockout while it
     * held three athletes and since grown past five is a knockout anyway —
     * and one set to round robin that grew must not try to draw a hundred
     * contests because of a box ticked when it was small.
     */
    it('ignores a stored preference the field has outgrown', function () {
        $category = classOf(8, '-outgrown');
        $category->forceFill(['draw_format_preference' => 'round_robin'])->save();

        expect(app(TournamentFormatPolicy::class)->resolveFor($category->refresh()))
            ->toBe(TournamentFormat::Knockout);
    });

    it('honours a stored preference the field still allows', function () {
        $category = classOf(4, '-kept');
        $category->forceFill(['draw_format_preference' => 'knockout'])->save();

        expect(app(TournamentFormatPolicy::class)->resolveFor($category->refresh()))
            ->toBe(TournamentFormat::Knockout);
    });
});

describe('drawing a round robin', function () {
    it('creates every pairing exactly once and no others', function (int $athletes, int $contests, int $rounds) {
        $category = classOf($athletes);

        $result = app(DrawGenerator::class)->generate($category);

        expect($result['format'])->toBe(TournamentFormat::RoundRobin)
            ->and($result['bouts'])->toBe($contests)
            ->and($result['rounds'])->toBe($rounds);

        $bouts = $category->refresh()->bouts()->get();

        expect($bouts)->toHaveCount($contests);

        $pairs = $bouts->map(function (Bout $b) {
            $pair = [$b->athlete_a_id, $b->athlete_b_id];
            sort($pair);

            return implode('-', $pair);
        });

        expect($pairs->unique())->toHaveCount($contests)
            // Nobody meets themselves.
            ->and($bouts->filter(fn (Bout $b) => $b->athlete_a_id === $b->athlete_b_id))->toBeEmpty()
            // And every pairing the field allows is present.
            ->and($pairs->unique()->count())->toBe(intdiv($athletes * ($athletes - 1), 2));
    })->with([
        'two athletes, one contest' => [2, 1, 1],
        'three, three contests over three rounds' => [3, 3, 3],
        'four, six contests over three rounds' => [4, 6, 3],
        'five, ten contests over five rounds' => [5, 10, 5],
    ]);

    /**
     * An odd field cannot pair everybody every round. Somebody rests — and the
     * rest is not written down, because a bout nobody fights would appear in
     * the running order, the exports and the standings.
     */
    it('rests an athlete each round in an odd field without inventing a bye', function () {
        $category = classOf(5, '-odd');
        app(DrawGenerator::class)->generate($category);

        $bouts = $category->refresh()->bouts()->get();

        expect($bouts->groupBy('round'))->toHaveCount(5)
            // Two contests a round, and the fifth athlete sits out.
            ->and($bouts->groupBy('round')->map->count()->unique()->values()->all())->toBe([2])
            ->and($bouts->where('is_bye', true))->toBeEmpty()
            ->and($bouts->whereNull('athlete_a_id'))->toBeEmpty()
            ->and($bouts->whereNull('athlete_b_id'))->toBeEmpty();

        // Each athlete rests exactly once across the five rounds.
        foreach ($category->drawnAthletes()->get() as $athlete) {
            $fought = $bouts->filter(
                fn (Bout $b) => $b->athlete_a_id === $athlete->id || $b->athlete_b_id === $athlete->id
            );

            expect($fought)->toHaveCount(4)
                ->and($fought->pluck('round')->unique())->toHaveCount(4);
        }
    });

    /** Nobody advances out of a round robin, so nothing points forward. */
    it('leaves the knockout advancement fields empty', function () {
        $category = classOf(4, '-links');
        app(DrawGenerator::class)->generate($category);

        foreach ($category->refresh()->bouts()->get() as $bout) {
            expect($bout->next_bout_id)->toBeNull()
                ->and($bout->next_bout_slot)->toBeNull()
                ->and($bout->is_bye)->toBeFalse();
        }
    });

    it('gives every contest a play code of its own', function () {
        $category = classOf(5, '-codes');
        app(DrawGenerator::class)->generate($category);

        $codes = $category->refresh()->bouts()->pluck('play_code');

        expect($codes->unique())->toHaveCount(10)
            ->and($codes->filter())->toHaveCount(10);
    });

    it('stores a round and a position within it', function () {
        $category = classOf(4, '-slots');
        app(DrawGenerator::class)->generate($category);

        $slots = $category->refresh()->bouts()->get()
            ->map(fn (Bout $b) => "{$b->round}:{$b->position_in_round}");

        expect($slots->unique())->toHaveCount(6)
            ->and($category->bouts()->min('round'))->toBe(1)
            ->and($category->bouts()->min('position_in_round'))->toBe(0);
    });

    /** The same field drawn twice gives the same schedule. */
    it('is deterministic', function () {
        $category = classOf(5, '-determinism');

        $first = app(DrawGenerator::class)->generate($category)
            && $category->refresh()->bouts()->get()
                ->map(fn (Bout $b) => "{$b->round}:{$b->position_in_round}:{$b->athlete_a_id}:{$b->athlete_b_id}")
                ->values()->all();

        $firstPairs = $category->bouts()->orderBy('round')->orderBy('position_in_round')->get()
            ->map(fn (Bout $b) => "{$b->round}:{$b->position_in_round}:{$b->athlete_a_id}:{$b->athlete_b_id}")
            ->all();

        app(DrawGenerator::class)->generate($category->refresh());

        $secondPairs = $category->refresh()->bouts()->orderBy('round')->orderBy('position_in_round')->get()
            ->map(fn (Bout $b) => "{$b->round}:{$b->position_in_round}:{$b->athlete_a_id}:{$b->athlete_b_id}")
            ->all();

        expect($secondPairs)->toBe($firstPairs);
    });

    /**
     * The circle method would give the athlete it holds still the same corner
     * in every contest they fight. An odd field balances exactly; an even one
     * fights an odd number of contests each, which cannot split evenly, so one
     * apart is the best arithmetic allows.
     */
    it('spreads the corners as evenly as the field permits', function (int $athletes, int $tolerance) {
        $category = classOf($athletes, '-corners');
        app(DrawGenerator::class)->generate($category);

        $bouts = $category->refresh()->bouts()->get();

        foreach ($category->drawnAthletes()->get() as $athlete) {
            $blue = $bouts->where('athlete_a_id', $athlete->id)->count();
            $green = $bouts->where('athlete_b_id', $athlete->id)->count();

            expect(abs($blue - $green))->toBeLessThanOrEqual(
                $tolerance,
                "athlete {$athlete->id} took {$blue} blue and {$green} green",
            );
        }
    })->with([
        'three balances exactly' => [3, 0],
        'four is one apart at worst' => [4, 1],
        'five balances exactly' => [5, 0],
    ]);
});

describe('the knockout, which must not have moved', function () {
    /**
     * The whole regression surface. Format resolution sits in front of the
     * bracket generator and must not have changed what it produces for any
     * field it was already used on.
     */
    it('still draws the bracket it always drew', function (int $athletes) {
        $category = classOf($athletes, '-ko');

        $direct = app(BracketGenerator::class)->generate($category);

        expect($direct['size'])->toBe(BracketSeeding::size($athletes))
            ->and($direct['byes'])->toBe($direct['size'] - $athletes)
            ->and($category->refresh()->bouts()->count())->toBe($direct['size'] - 1);

        // Every bout but the final points at the one its winner walks into.
        $bouts = $category->bouts()->get();
        $finals = $bouts->whereNull('next_bout_id');

        expect($finals)->toHaveCount(1)
            ->and($bouts->whereNotNull('next_bout_id'))->toHaveCount($bouts->count() - 1);
    })->with([6, 7, 8, 9, 12, 16]);

    /** Through the new door, a large field reaches the same generator. */
    it('routes a field of six or more to the bracket', function (int $athletes) {
        $category = classOf($athletes, '-routed');

        $result = app(DrawGenerator::class)->generate($category);

        expect($result['format'])->toBe(TournamentFormat::Knockout)
            ->and($category->refresh()->draw_format)->toBe('knockout')
            ->and($category->bouts()->whereNotNull('next_bout_id')->count())->toBeGreaterThan(0);
    })->with([6, 8, 16]);
});

describe('the knockout override in a small field', function () {
    it('refuses without a reason', function () {
        $category = classOf(4, '-noreason');

        expect(fn () => app(DrawGenerator::class)->generate($category, TournamentFormat::Knockout))
            ->toThrow(DrawFormatException::class);

        expect($category->refresh()->bouts()->count())->toBe(0)
            ->and($category->draw_format)->toBeNull();
    });

    it('records who signed for it, why, and when', function () {
        $category = classOf(4, '-signed');

        $this->freezeSecond();

        $result = app(DrawGenerator::class)->generate(
            $category,
            TournamentFormat::Knockout,
            overrideReason: 'Local invitational, agreed with the delegations.',
            user: $this->admin,
        );

        $category->refresh();

        expect($result['override'])->toBeTrue()
            ->and($category->draw_format)->toBe('knockout')
            ->and($category->draw_format_override_reason)->toBe('Local invitational, agreed with the delegations.')
            ->and($category->draw_format_override_by)->toBe($this->admin->id)
            ->and($category->draw_format_override_at?->timestamp)->toBe(now()->timestamp)
            ->and($category->formatWasOverridden())->toBeTrue();
    });

    /** A bracket of four is still a bracket of four. */
    it('draws the ordinary bracket once it is authorised', function () {
        $category = classOf(4, '-authorised');

        app(DrawGenerator::class)->generate(
            $category,
            TournamentFormat::Knockout,
            overrideReason: 'Local rules.',
            user: $this->admin,
        );

        $bouts = $category->refresh()->bouts()->get();

        expect($bouts)->toHaveCount(3)
            ->and($bouts->whereNotNull('next_bout_id'))->toHaveCount(2)
            ->and($category->draw_bucket_size)->toBe(4);
    });

    /** Redrawn compliantly, the signature must not linger. */
    it('clears the signature when the class is redrawn as a round robin', function () {
        $category = classOf(4, '-cleared');

        app(DrawGenerator::class)->generate(
            $category,
            TournamentFormat::Knockout,
            overrideReason: 'Local rules.',
            user: $this->admin,
        );

        app(DrawGenerator::class)->generate($category->refresh(), TournamentFormat::RoundRobin);

        $category->refresh();

        expect($category->draw_format)->toBe('round_robin')
            ->and($category->draw_format_override_reason)->toBeNull()
            ->and($category->draw_format_override_by)->toBeNull()
            ->and($category->draw_format_override_at)->toBeNull()
            ->and($category->formatWasOverridden())->toBeFalse();
    });

    /** The format the rule gives needs no signature at all. */
    it('asks nobody to sign for the compliant format', function () {
        $category = classOf(3, '-compliant');

        $result = app(DrawGenerator::class)->generate($category);

        expect($result['override'])->toBeFalse()
            ->and($category->refresh()->draw_format_override_at)->toBeNull();
    });
});

describe('what the server refuses whatever the browser asks for', function () {
    it('will not draw a round robin for a field the rule gives to the bracket', function (int $athletes) {
        $category = classOf($athletes, '-toolarge');

        expect(fn () => app(DrawGenerator::class)->generate($category, TournamentFormat::RoundRobin))
            ->toThrow(DrawFormatException::class);

        expect($category->refresh()->bouts()->count())->toBe(0);
    })->with([6, 8, 16]);

    it('will not place an athlete in a class that has somebody to fight', function () {
        $category = classOf(4, '-notsolo');

        expect(fn () => app(DrawGenerator::class)->generate($category, TournamentFormat::Placement))
            ->toThrow(DrawFormatException::class);
    });

    it('will not draw a contest for a class of one', function (TournamentFormat $format) {
        $category = classOf(1, '-alone');

        expect(fn () => app(DrawGenerator::class)->generate($category, $format))
            ->toThrow(DrawFormatException::class);
    })->with([
        'round robin' => [TournamentFormat::RoundRobin],
        'knockout' => [TournamentFormat::Knockout],
    ]);

    it('refuses a round robin the generator is handed directly', function () {
        $category = classOf(8, '-direct');

        expect(fn () => app(RoundRobinGenerator::class)->generate($category))
            ->toThrow(RuntimeException::class);
    });
});

describe('the class of one', function () {
    it('is drawn as a placement with no contests at all', function () {
        $category = classOf(1, '-solo');

        $result = app(DrawGenerator::class)->generate($category);

        $category->refresh();

        expect($result['format'])->toBe(TournamentFormat::Placement)
            ->and($result['bouts'])->toBe(0)
            ->and($category->bouts()->count())->toBe(0)
            ->and($category->draw_format)->toBe('placement')
            ->and($category->isPlacement())->toBeTrue();
    });

    /**
     * The reason hasDraw() could not go on asking the bouts table: a placement
     * is a draw, and every screen that gates on this would otherwise offer to
     * draw the class again.
     */
    it('counts as drawn even though nobody fights', function () {
        $category = classOf(1, '-drawn');

        expect($category->hasDraw())->toBeFalse();

        app(DrawGenerator::class)->generate($category);

        expect($category->refresh()->hasDraw())->toBeTrue();
    });

    /** Registered is not champion. Somebody has to say so. */
    it('awards nobody until an administrator places them', function () {
        $category = classOf(1, '-unplaced');
        app(DrawGenerator::class)->generate($category);

        expect($category->refresh()->draw_placement_athlete_id)->toBeNull()
            ->and($category->draw_placement_at)->toBeNull();

        $this->freezeSecond();

        $athlete = app(DrawGenerator::class)->placeSoleAthlete($category, $this->admin);

        $category->refresh();

        expect($category->draw_placement_athlete_id)->toBe($athlete->id)
            ->and($category->draw_placement_by)->toBe($this->admin->id)
            ->and($category->draw_placement_at?->timestamp)->toBe(now()->timestamp);
    });

    it('refuses to place anybody in a class with contests', function () {
        $category = classOf(4, '-hascontests');
        app(DrawGenerator::class)->generate($category);

        expect(fn () => app(DrawGenerator::class)->placeSoleAthlete($category->refresh(), $this->admin))
            ->toThrow(DrawFormatException::class);
    });
});

describe('draws that already existed', function () {
    /**
     * The upgrade must not reinterpret a competition. Everything drawn before
     * formats existed is a bracket, and a class of three that was drawn as one
     * stays one — being told the rule prefers a round robin does not redraw a
     * table people are already fighting on.
     */
    it('reads an unstamped bracket as a knockout', function () {
        $category = classOf(3, '-legacy');
        app(BracketGenerator::class)->generate($category);

        // As a row written before the column existed would look.
        $category->forceFill(['draw_format' => null, 'draw_format_preference' => null])->save();
        $category->refresh();

        expect($category->hasDraw())->toBeTrue()
            ->and($category->drawFormat())->toBe(TournamentFormat::Knockout)
            ->and($category->isRoundRobin())->toBeFalse()
            // And what it *would* be drawn as now is a separate question, which
            // nothing reads to render an existing draw.
            ->and($category->resolvedFormat())->toBe(TournamentFormat::RoundRobin);
    });

    it('leaves an undrawn class with no format at all', function () {
        expect(classOf(4, '-undrawn')->drawFormat())->toBeNull();
    });
});
