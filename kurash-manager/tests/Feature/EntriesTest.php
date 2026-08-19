<?php

use App\Livewire\Competition\Entries;
use App\Models\User;
use App\Services\BracketGenerator;
use Livewire\Livewire;

beforeEach(function () {
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

it('sends guests to the login page', function () {
    [$category] = categoryWithAthletes(2);

    $this->get(route('entries.index', $category->ageCategory->championship))->assertRedirect(route('login'));
});

it('renders for a signed-in user', function () {
    [$category] = categoryWithAthletes(4);

    $this->actingAs($this->viewer);

    $this->get(route('entries.index', $category->ageCategory->championship))->assertOk();
});

/**
 * Specification §6.2: an entry is an athlete who made the scale, not everyone
 * on the registration list. Counting registrations would start a draw with
 * athletes who are not eligible to be in it.
 */
it('counts only athletes who passed the scale as entries', function () {
    [$category] = categoryWithAthletes(5);
    $category->athletes()->update(['weighin_status' => 'pass']);
    $category->athletes()->limit(2)->update(['weighin_status' => 'fail']);

    $this->actingAs($this->viewer);

    $row = Livewire::test(Entries::class, ['championship' => $category->ageCategory->championship])
        ->viewData('byWeight');

    $row = collect($row)->firstWhere(fn (array $r) => $r['category']->id === $category->id);

    expect($row['registered'])->toBe(5)
        ->and($row['cleared'])->toBe(3)
        ->and($row['bracket'])->toBe('Semi Final');
});

it('reports a class as not started until it has bouts, then done', function () {
    [$category] = categoryWithAthletes(4);
    $category->athletes()->update(['weighin_status' => 'pass']);
    $championship = $category->ageCategory->championship;

    $this->actingAs($this->viewer);

    $rowFor = fn (array $rows) => collect($rows)->firstWhere(fn (array $r) => $r['category']->id === $category->id);

    $before = $rowFor(Livewire::test(Entries::class, ['championship' => $championship])->viewData('byWeight'));

    expect($before['drawn'])->toBeFalse();

    app(BracketGenerator::class)->generate($category);

    $after = $rowFor(Livewire::test(Entries::class, ['championship' => $championship])->viewData('byWeight'));

    expect($after['drawn'])->toBeTrue();
});

it('groups entries by delegation, largest first', function () {
    [$category] = categoryWithAthletes(4);

    $athletes = $category->athletes()->orderBy('draw_number')->get();
    $athletes[0]->update(['noc_code' => 'UZB', 'noc_name' => 'Uzbekistan']);
    $athletes[1]->update(['noc_code' => 'UZB', 'noc_name' => 'Uzbekistan']);
    $athletes[2]->update(['noc_code' => 'UZB', 'noc_name' => 'Uzbekistan']);
    $athletes[3]->update(['noc_code' => 'IRI', 'noc_name' => 'Iran']);

    $this->actingAs($this->viewer);

    $byNoc = Livewire::test(Entries::class, ['championship' => $category->ageCategory->championship])
        ->viewData('byNoc');

    expect(collect($byNoc)->pluck('noc')->all())->toBe(['UZB', 'IRI'])
        ->and($byNoc[0]['total'])->toBe(3);
});

it('counts how many classes are ready to draw', function () {
    [$category] = categoryWithAthletes(4);
    $category->athletes()->update(['weighin_status' => 'pass']);

    $this->actingAs($this->viewer);

    expect(Livewire::test(Entries::class, ['championship' => $category->ageCategory->championship])
        ->viewData('readyToDraw'))->toBe(1);
});
