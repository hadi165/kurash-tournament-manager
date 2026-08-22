<?php

use App\Livewire\Competition\Categories;
use App\Livewire\Competition\Championships;
use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\Registration;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use Livewire\Livewire;

/**
 * A championship says which competitions and age groups it runs, and every
 * screen after it is bound by that answer.
 *
 * What this is really testing is that there is nowhere else for an age group to
 * come from. The old system had divisions as free text — somebody typed "Men
 * Senior" into a box — so a championship could acquire a junior division by
 * spelling, and nothing could tell that it had.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);
});

/** A championship running one competition and one age group. */
function seniorMenOnly(): Championship
{
    return Championship::factory()->create([
        'title' => 'Men Senior Open 2026',
        'genders' => ['M'],
        'age_groups' => ['Senior'],
    ]);
}

describe('creating a championship', function () {
    it('records the competitions and age groups it runs', function () {
        Livewire::test(Championships::class)
            ->set('title', 'Asian Kurash 2026')
            ->set('genders', ['M', 'F'])
            ->set('ageGroups', 'Senior, Junior, Cadet')
            ->call('save')
            ->assertHasNoErrors();

        $championship = Championship::firstWhere('title', 'Asian Kurash 2026');

        expect($championship->configuredGenders())->toBe(['M', 'F'])
            ->and($championship->configuredAgeGroups())->toBe(['Senior', 'Junior', 'Cadet']);
    });

    it('refuses one that runs no competition at all', function () {
        Livewire::test(Championships::class)
            ->set('title', 'Nobody Open')
            ->set('genders', [])
            ->call('save')
            ->assertHasErrors('genders');

        expect(Championship::where('title', 'Nobody Open')->exists())->toBeFalse();
    });

    it('refuses one that names no age group', function () {
        Livewire::test(Championships::class)
            ->set('title', 'Ageless Open')
            ->set('ageGroups', ' , , ')
            ->call('save')
            ->assertHasErrors('ageGroups');
    });

    it('keeps the list tidy rather than storing what was typed', function () {
        Livewire::test(Championships::class)
            ->set('title', 'Tidy Open')
            ->set('ageGroups', ' Senior ,, Junior , Senior ')
            ->call('save');

        expect(Championship::firstWhere('title', 'Tidy Open')->configuredAgeGroups())
            ->toBe(['Senior', 'Junior']);
    });

    /**
     * Withdrawing an age group that divisions are built on would leave them
     * configured for nothing, so it is refused while they exist.
     */
    it('refuses to withdraw an age group still in use', function () {
        $championship = Championship::factory()->create([
            'genders' => ['M'],
            'age_groups' => ['Senior', 'Junior'],
        ]);

        AgeCategory::factory()->for($championship)->create(['gender' => 'M', 'age_group' => 'Junior']);

        Livewire::test(Championships::class)
            ->call('edit', $championship->id)
            ->set('ageGroups', 'Senior')
            ->call('save')
            ->assertHasErrors('ageGroups');

        expect($championship->refresh()->configuredAgeGroups())->toContain('Junior');
    });

    it('refuses to withdraw a competition still in use', function () {
        $championship = Championship::factory()->create([
            'genders' => ['M', 'F'],
            'age_groups' => ['Senior'],
        ]);

        AgeCategory::factory()->for($championship)->create(['gender' => 'F', 'age_group' => 'Senior']);

        Livewire::test(Championships::class)
            ->call('edit', $championship->id)
            ->set('genders', ['M'])
            ->call('save')
            ->assertHasErrors('ageGroups');
    });
});

describe('divisions come from the configuration', function () {
    it('offers only the age groups the championship runs', function () {
        Livewire::test(Categories::class, ['championship' => seniorMenOnly()])
            ->assertSee('Senior')
            ->assertDontSee('Junior')
            ->assertDontSee('Cadet');
    });

    it('offers only the competitions the championship runs', function () {
        $womenOnly = Championship::factory()->create(['genders' => ['F'], 'age_groups' => ['Senior']]);

        Livewire::test(Categories::class, ['championship' => $womenOnly])
            ->assertSee('Women')
            ->assertDontSee('Men');
    });

    it('names the division from the pair rather than from a typed name', function () {
        $championship = Championship::factory()->create([
            'genders' => ['F'],
            'age_groups' => ['Cadet'],
        ]);

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('gender', 'F')
            ->set('ageGroup', 'Cadet')
            ->set('weightLabels', '-40, -44')
            ->call('save')
            ->assertHasNoErrors();

        expect($championship->ageCategories()->value('name'))->toBe('Women Cadet');
    });

    /** The select is not the authority. A crafted request meets the same rule. */
    it('refuses an age group the championship never declared', function () {
        $championship = seniorMenOnly();

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('gender', 'M')
            ->set('ageGroup', 'Junior')
            ->set('weightLabels', '-60, -66')
            ->call('save')
            ->assertHasErrors('ageGroup');

        expect($championship->ageCategories()->count())->toBe(0);
    });

    it('refuses a competition the championship never declared', function () {
        $championship = seniorMenOnly();

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('gender', 'F')
            ->set('ageGroup', 'Senior')
            ->set('weightLabels', '-57')
            ->call('save')
            ->assertHasErrors('ageGroup');

        expect($championship->ageCategories()->count())->toBe(0);
    });

    it('refuses the same division twice', function () {
        $championship = seniorMenOnly();
        AgeCategory::factory()->for($championship)->create(['gender' => 'M', 'age_group' => 'Senior']);

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('gender', 'M')
            ->set('ageGroup', 'Senior')
            ->set('weightLabels', '-60')
            ->call('save')
            ->assertHasErrors('ageGroup');

        expect($championship->ageCategories()->count())->toBe(1);
    });

    /** A class in a women's division is a women's class by definition. */
    it('gives every weight class the division\'s own competition', function () {
        $championship = Championship::factory()->create(['genders' => ['F'], 'age_groups' => ['Senior']]);

        Livewire::test(Categories::class, ['championship' => $championship])
            ->set('gender', 'F')
            ->set('ageGroup', 'Senior')
            ->set('weightLabels', '-52, -57, -63')
            ->call('save');

        $genders = WeightCategory::whereIn(
            'age_category_id',
            $championship->ageCategories()->pluck('id')
        )->pluck('gender')->unique();

        expect($genders->all())->toBe(['F']);
    });
});

/**
 * Registration is scoped to a competition, not to a division. The age groups
 * were settled when the championship was created, so they are a field on the
 * entry rather than a place to navigate to.
 */
describe('registration is bound by the competition', function () {
    beforeEach(function () {
        $this->championship = seniorMenOnly();
        $this->division = AgeCategory::factory()->for($this->championship)
            ->create(['gender' => 'M', 'age_group' => 'Senior']);
        $this->class = WeightCategory::factory()->create([
            'age_category_id' => $this->division->id,
            'label' => '-66',
            'gender' => 'M',
        ]);
    });

    it('opens on the competition it was asked for', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->assertSet('gender', 'M')
            ->assertSet('age_category_id', $this->division->id);
    });

    it('refuses a competition this championship does not run', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->set('fullname', 'Not Entered Here')
            ->set('noc_code', 'UZB')
            ->set('gender', 'F')
            ->set('weight_category_id', $this->class->id)
            ->call('save')
            ->assertHasErrors('gender');

        expect(Athlete::where('fullname', 'Not Entered Here')->exists())->toBeFalse();
    });

    /** There is no page for a competition the championship never declared. */
    it('has no page for a competition that does not exist here', function () {
        $this->get(route('athletes.index', [
            'championship' => $this->championship,
            'competition' => 'F',
        ]))->assertNotFound();
    });
});

describe('one competition, several age groups', function () {
    beforeEach(function () {
        $this->championship = Championship::factory()->create([
            'genders' => ['M'],
            'age_groups' => ['Senior', 'Junior'],
        ]);

        $this->senior = AgeCategory::factory()->for($this->championship)
            ->create(['gender' => 'M', 'age_group' => 'Senior', 'sort_order' => 0]);
        $this->junior = AgeCategory::factory()->for($this->championship)
            ->create(['gender' => 'M', 'age_group' => 'Junior', 'sort_order' => 1]);

        $this->seniorClass = WeightCategory::factory()->create([
            'age_category_id' => $this->senior->id, 'label' => '-66', 'gender' => 'M',
        ]);
        $this->juniorClass = WeightCategory::factory()->create([
            'age_category_id' => $this->junior->id, 'label' => '-60', 'gender' => 'M',
        ]);
    });

    it('registers into whichever age group was chosen', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->set('fullname', 'Junior Entrant')
            ->set('noc_code', 'UZB')
            ->set('age_category_id', $this->junior->id)
            ->set('weight_category_id', $this->juniorClass->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(Athlete::firstWhere('fullname', 'Junior Entrant')->age_category_id)
            ->toBe($this->junior->id);
    });

    /** One screen for the competition, so both age groups are on it. */
    it('lists everyone in the competition, whatever their age group', function () {
        Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->senior->id,
            'weight_category_id' => $this->seniorClass->id,
            'fullname' => 'A Senior',
            'gender' => 'M',
        ]);

        Athlete::factory()->create([
            'championship_id' => $this->championship->id,
            'age_category_id' => $this->junior->id,
            'weight_category_id' => $this->juniorClass->id,
            'fullname' => 'A Junior',
            'gender' => 'M',
        ]);

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->assertSee('A Senior')
            ->assertSee('A Junior');
    });

    /**
     * A weight class belonging to the other age group is not reachable from
     * the one that was chosen, however the request was made.
     */
    it('refuses a weight class from the age group that was not chosen', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->set('fullname', 'Mismatched')
            ->set('noc_code', 'UZB')
            ->set('age_category_id', $this->senior->id)
            ->set('weight_category_id', $this->juniorClass->id)
            ->call('save')
            ->assertHasErrors('weight_category_id');

        expect(Athlete::where('fullname', 'Mismatched')->exists())->toBeFalse();
    });

    /** An age group from another championship is simply not found. */
    it('refuses an age group from another championship', function () {
        $elsewhere = AgeCategory::factory()->create(['gender' => 'M', 'age_group' => 'Senior']);

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->set('fullname', 'Forged')
            ->set('noc_code', 'UZB')
            ->set('age_category_id', $elsewhere->id)
            ->set('weight_category_id', $this->seniorClass->id)
            ->call('save')
            ->assertHasErrors('age_category_id');

        expect(Athlete::where('fullname', 'Forged')->exists())->toBeFalse();
    });
});

describe('the running order is bound by the configuration', function () {
    it('offers only the competitions the championship runs', function () {
        $womenOnly = Championship::factory()->create(['genders' => ['F'], 'age_groups' => ['Senior']]);
        AgeCategory::factory()->for($womenOnly)->create(['gender' => 'F', 'age_group' => 'Senior']);

        Livewire::test(FightOrder::class, ['championship' => $womenOnly])
            ->assertSee('Women')
            ->assertDontSee('Men');
    });
});
