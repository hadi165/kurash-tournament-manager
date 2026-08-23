<?php

use App\Livewire\Competition\WeighIn;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\WeightValidator;
use Livewire\Livewire;

/**
 * The weigh-in rules.
 *
 * The old rule accepted a 500-gram window below the ceiling — a -60 class took
 * 59.5 to 60.0 and rejected everybody lighter. These tests pin the rule the
 * federation actually runs, including the three readings §18 names by hand.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);

    $this->division = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);

    // A division built the way a federation writes one, so the class below is
    // a real neighbour rather than a fixture convenience.
    $this->classes = collect(['-56' => 56.0, '-60' => 60.0, '-66' => 66.0, '-73' => 73.0])
        ->map(fn (float $max, string $label) => WeightCategory::factory()->create([
            'age_category_id' => $this->division->id,
            'label' => $label,
            'min_kg' => null,
            'max_kg' => $max,
            'gender' => 'M',
        ]))
        ->keyBy('label');

    $this->open = WeightCategory::factory()->create([
        'age_category_id' => $this->division->id,
        'label' => '+73',
        'min_kg' => 73.0,
        'max_kg' => null,
        'gender' => 'M',
    ]);

    $this->validator = app(WeightValidator::class);
});

describe('the accepted band', function () {
    it('runs from the class below to its own limit, with the tolerance on both', function () {
        $range = $this->validator->rangeFor($this->classes['-60']);

        expect($range->min)->toBe(55.5)
            // The limit plus 500 grams: -60 accepts up to 60.5.
            ->and($range->max)->toBe(60.5)
            ->and($range->nominalMin)->toBe(56.0)
            ->and($range->nominalMax)->toBe(60.0);
    });

    /** The tolerance the federation allows, on the scale where it is used. */
    it('allows 500 grams over the limit of a minus class', function (string $kg, bool $accepted) {
        expect($this->validator->check($this->classes['-60'], (float) $kg)->accepted)->toBe($accepted);
    })->with([
        ['60.000', true],
        ['60.400', true],
        ['60.500', true],
        ['60.501', false],
        ['60.600', false],
    ]);

    /** The three readings §18 names. All three failed under the old rule. */
    it('accepts the readings the rules name for -60', function (string $kg) {
        expect($this->validator->check($this->classes['-60'], (float) $kg)->accepted)->toBeTrue();
    })->with(['56.100', '56.200', '56.500']);

    it('accepts a weight right on either bound', function () {
        $category = $this->classes['-60'];

        expect($this->validator->check($category, 55.5)->accepted)->toBeTrue()
            ->and($this->validator->check($category, 60.5)->accepted)->toBeTrue();
    });

    it('rejects a weight over even the tolerance', function () {
        $verdict = $this->validator->check($this->classes['-60'], 60.501);

        expect($verdict->accepted)->toBeFalse()
            ->and($verdict->reason)->toContain('above')
            // The number that was missed, not the class label alone.
            ->and($verdict->reason)->toContain('60.5');
    });

    it('rejects a weight below even the tolerance', function () {
        $verdict = $this->validator->check($this->classes['-60'], 55.4);

        expect($verdict->accepted)->toBeFalse()
            ->and($verdict->reason)->toContain('below')
            ->and($verdict->range->label())->toBe('55.5 – 60.5 kg');
    });

    it('gives the lightest class in a division no floor at all', function () {
        $range = $this->validator->rangeFor($this->classes['-56']);

        // The lightest athletes have to land somewhere.
        expect($range->min)->toBeNull()
            ->and($range->max)->toBe(56.5)
            ->and($this->validator->check($this->classes['-56'], 41.2)->accepted)->toBeTrue();
    });

    /** Nothing to add a tolerance to, because there is no limit. */
    it('gives an open class a floor and no ceiling', function () {
        $range = $this->validator->rangeFor($this->open);

        expect($range->min)->toBe(72.5)
            ->and($range->nominalMax)->toBeNull()
            ->and($range->max)->toBeNull()
            ->and($this->validator->check($this->open, 140.0)->accepted)->toBeTrue()
            ->and($this->validator->check($this->open, 72.4)->accepted)->toBeFalse();
    });

    /**
     * The bands are derived, not stored, so inserting a class moves the floor
     * of the one above it without anybody maintaining a table of ranges.
     */
    it('moves the class above when a new class is inserted below it', function () {
        expect($this->validator->rangeFor($this->classes['-66'])->min)->toBe(59.5);

        WeightCategory::factory()->create([
            'age_category_id' => $this->division->id,
            'label' => '-63',
            'min_kg' => null,
            'max_kg' => 63.0,
            'gender' => 'M',
        ]);

        expect($this->validator->rangeFor($this->classes['-66'])->min)->toBe(62.5);
    });

    /** A men's -60 is not bounded by a women's class sharing the division. */
    it('ignores classes of another gender', function () {
        WeightCategory::factory()->create([
            'age_category_id' => $this->division->id,
            'label' => '-57',
            'min_kg' => null,
            'max_kg' => 57.0,
            'gender' => 'F',
        ]);

        expect($this->validator->rangeFor($this->classes['-60'])->min)->toBe(55.5);
    });

    it('refuses an athlete with no class rather than passing them', function () {
        $verdict = $this->validator->check(null, 60.0);

        expect($verdict->accepted)->toBeFalse()
            ->and($verdict->reason)->toContain('No weight class');
    });
});

describe('the model reads the same engine', function () {
    it('answers admits() from the division rather than from its own row', function () {
        expect($this->classes['-60']->admits(56.1))->toBeTrue()
            ->and($this->classes['-60']->admits(59.9))->toBeTrue()
            ->and($this->classes['-60']->admits(55.4))->toBeFalse();
    });
});

describe('at the scale', function () {
    it('passes an athlete the old rule would have failed', function () {
        $athlete = Athlete::factory()->create([
            'championship_id' => $this->division->championship_id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->classes['-60']->id,
            'fullname' => 'Test Athlete',
        ]);

        Livewire::test(WeighIn::class, ['championship' => $this->division->championship, 'competition' => 'M'])
            ->set("weights.{$athlete->id}", '56.100')
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_status)->toBe('pass')
            ->and((float) $athlete->weighin_kg)->toBe(56.1);
    });

    it('tells the official what would have passed when one fails', function () {
        $athlete = Athlete::factory()->create([
            'championship_id' => $this->division->championship_id,
            'age_category_id' => $this->division->id,
            'weight_category_id' => $this->classes['-60']->id,
            'fullname' => 'Heavy Athlete',
        ]);

        $component = Livewire::test(WeighIn::class, ['championship' => $this->division->championship, 'competition' => 'M'])
            ->set("weights.{$athlete->id}", '61.4')
            ->call('record', $athlete->id);

        expect($athlete->refresh()->weighin_status)->toBe('fail');

        // The band on screen, not just the refusal: an official at the scale
        // needs to be able to say what the athlete needed.
        $component->assertSee('55.5 – 60.5 kg')
            ->assertSee('above');
    });
});
