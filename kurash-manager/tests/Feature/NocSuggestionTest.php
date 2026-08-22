<?php

use App\Livewire\Competition\Registration;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\User;
use App\Models\WeightCategory;
use App\Support\Noc;
use Livewire\Livewire;

/**
 * Suggesting a nation as its code is typed.
 *
 * Two hundred codes, and none of them derivable — BRN is Bahrain and BRU is
 * Brunei. Getting one wrong puts another country's flag beside an athlete's
 * name on a screen in front of their delegation, which is why the field
 * suggests as it is typed rather than waiting to complain at the end.
 */
describe('the code table', function () {
    it('knows a country for every code it knows a flag for', function () {
        foreach (Noc::codes() as $code) {
            expect(Noc::name($code))->not->toBeNull()
                ->and(Noc::iso($code))->not->toBeNull();
        }

        expect(Noc::codes())->toHaveCount(count(Noc::all()));
    });

    it('names the nations a competition names, not the ones a map does', function () {
        expect(Noc::name('IRI'))->toBe('Iran')
            ->and(Noc::name('TPE'))->toBe('Chinese Taipei')
            ->and(Noc::name('GBR'))->toBe('Great Britain')
            // The pair this table exists for: neither is derivable.
            ->and(Noc::name('BRN'))->toBe('Bahrain')
            ->and(Noc::name('BRU'))->toBe('Brunei');
    });

    it('reads a typed code however it was typed', function () {
        expect(Noc::exists('uzb'))->toBeTrue()
            ->and(Noc::exists(' UZB '))->toBeTrue()
            ->and(Noc::exists('ZZZ'))->toBeFalse()
            ->and(Noc::exists(''))->toBeFalse()
            ->and(Noc::exists(null))->toBeFalse();
    });
});

describe('matching what has been typed', function () {
    it('matches from the start of the code, not anywhere inside it', function () {
        $matches = Noc::startingWith('IR', 20);

        expect(array_keys($matches))->toBe(['IRI', 'IRL', 'IRQ'])
            ->and($matches['IRI'])->toBe('Iran');
    });

    it('narrows as more is typed', function () {
        $one = Noc::startingWith('I', 50);
        $two = Noc::startingWith('IR', 50);

        expect(array_keys($one))->toContain('IRI', 'ITA', 'IND', 'IRQ')
            ->and(count($two))->toBeLessThan(count($one))
            ->and(array_keys($two))->not->toContain('ITA');
    });

    /** A code is read as a prefix, so "RI" is not a way to reach Iran. */
    it('does not match the middle of a code', function () {
        expect(Noc::startingWith('RI'))->toBe([]);
    });

    it('suggests nothing until something is typed', function () {
        expect(Noc::startingWith(''))->toBe([])
            ->and(Noc::startingWith(null))->toBe([])
            ->and(Noc::startingWith('   '))->toBe([]);
    });

    it('reads lower case the same way', function () {
        expect(Noc::startingWith('ir', 20))->toBe(Noc::startingWith('IR', 20));
    });

    it('holds the list to the length asked for', function () {
        expect(Noc::startingWith('B', 3))->toHaveCount(3);
    });

    /** A code typed in full is one match, which is what closes the list. */
    it('returns the one code when it is typed out', function () {
        expect(Noc::startingWith('IRI'))->toBe(['IRI' => 'Iran']);
    });
});

describe('the registration form', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->division = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);
        $this->class = WeightCategory::factory()->create([
            'age_category_id' => $this->division->id,
            'label' => '-66',
            'gender' => 'M',
        ]);
    });

    /** The list is handed over once rather than fetched per keystroke. */
    it('carries the whole table to the page', function () {
        Livewire::test(Registration::class, [
            'championship' => $this->division->championship,
            'competition' => 'M',
        ])
            ->assertViewHas('nations', fn (array $nations) => $nations['IRI'] === 'Iran')
            ->assertSee('nocSuggest')
            ->assertSee('Chinese Taipei');
    });

    it('refuses a code no nation uses', function () {
        Livewire::test(Registration::class, [
            'championship' => $this->division->championship,
            'competition' => 'M',
        ])
            ->set('fullname', 'Nowhere Man')
            ->set('noc_code', 'ZZZ')
            ->set('weight_category_id', $this->class->id)
            ->call('save')
            ->assertHasErrors('noc_code');

        expect(Athlete::where('fullname', 'Nowhere Man')->exists())->toBeFalse();
    });

    /** Named in the message, so it is clear which of the fields is wrong. */
    it('says which code it did not recognise', function () {
        $form = Livewire::test(Registration::class, [
            'championship' => $this->division->championship,
            'competition' => 'M',
        ])
            ->set('fullname', 'Nowhere Man')
            ->set('noc_code', 'zzz')
            ->set('weight_category_id', $this->class->id)
            ->call('save')
            ->assertHasErrors('noc_code');

        expect($form->instance()->getErrorBag()->first('noc_code'))
            ->toBe('"ZZZ" is not a recognised NOC code.');
    });

    it('accepts one typed in lower case', function () {
        Livewire::test(Registration::class, [
            'championship' => $this->division->championship,
            'competition' => 'M',
        ])
            ->set('fullname', 'Correctly Entered')
            ->set('noc_code', 'iri')
            ->set('weight_category_id', $this->class->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(Athlete::firstWhere('fullname', 'Correctly Entered')->noc_code)->toBe('IRI');
    });
});
