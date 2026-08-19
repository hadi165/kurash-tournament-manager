<?php

use App\Livewire\Competition\Archive;
use App\Livewire\Competition\WeighIn;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Services\BoutAdvancer;
use App\Services\BracketGenerator;
use App\Services\ChampionshipArchivedException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

/** A finished championship: bracket drawn, every bout decided. */
function finishedChampionship(int $athletes = 4): array
{
    [$category] = categoryWithAthletes($athletes);
    app(BracketGenerator::class)->generate($category);
    runTournament($category);

    return [$category->ageCategory->championship->refresh(), $category];
}

describe('access', function () {
    it('sends guests to the login page', function () {
        $this->get(route('archive.index'))->assertRedirect(route('login'));
    });

    it('lets a viewer read it but not archive', function () {
        [$championship] = finishedChampionship();

        $this->actingAs($this->viewer);
        $this->get(route('archive.index'))->assertOk();

        Livewire::test(Archive::class)
            ->call('archive', $championship->id)
            ->assertForbidden();

        expect($championship->refresh()->isArchived())->toBeFalse();
    });
});

describe('archiving', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('closes a championship whose contests are all decided', function () {
        [$championship] = finishedChampionship();

        Livewire::test(Archive::class)->call('archive', $championship->id);

        $championship->refresh();

        expect($championship->isArchived())->toBeTrue()
            ->and($championship->archived_by)->toBe($this->admin->id)
            ->and($championship->events()->where('action', 'archived')->count())->toBe(1);
    });

    it('refuses while a contest is still undecided', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        $championship = $category->ageCategory->championship;

        Livewire::test(Archive::class)->call('archive', $championship->id);

        expect($championship->refresh()->isArchived())->toBeFalse();
    });
});

/**
 * The point of the archive. A closed championship that still accepts writes is
 * a filter over a list, not a record — these are what make it the latter.
 */
describe('the freeze', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('refuses to change an athlete', function () {
        [$championship, $category] = finishedChampionship();
        $championship->archive($this->admin);

        $athlete = $category->athletes()->first();

        expect(fn () => $athlete->update(['fullname' => 'Renamed']))
            ->toThrow(ChampionshipArchivedException::class);

        expect($athlete->refresh()->fullname)->not->toBe('Renamed');
    });

    it('refuses to overturn a decided result', function () {
        [$championship, $category] = finishedChampionship();
        $championship->archive($this->admin);

        $final = $category->bouts()->whereNull('next_bout_id')->first();
        $loserId = $final->loserId();

        expect(fn () => app(BoutAdvancer::class)->recordResult($final, $loserId))
            ->toThrow(ChampionshipArchivedException::class);

        expect($final->refresh()->winner_athlete_id)->not->toBe($loserId);
    });

    it('refuses to redraw the bracket', function () {
        [$championship, $category] = finishedChampionship();
        $championship->archive($this->admin);

        expect(fn () => app(BracketGenerator::class)->generate($category, discardResults: true))
            ->toThrow(ChampionshipArchivedException::class);
    });

    it('refuses to register a new athlete', function () {
        [$championship, $category] = finishedChampionship();
        $championship->archive($this->admin);

        expect(fn () => Athlete::register([
            'championship_id' => $championship->id,
            'age_category_id' => $category->age_category_id,
            'weight_category_id' => $category->id,
            'fullname' => 'Late Entry',
            'noc_code' => 'UZB',
            'gender' => 'M',
        ]))->toThrow(ChampionshipArchivedException::class);
    });

    it('leaves other championships alone', function () {
        [$archived] = finishedChampionship();
        [$open, $openCategory] = finishedChampionship();

        $archived->archive($this->admin);

        $athlete = $openCategory->athletes()->first();
        $athlete->update(['fullname' => 'Still Editable']);

        expect($athlete->refresh()->fullname)->toBe('Still Editable');
    });

    it('stops the weigh-in screen from recording a weight', function () {
        [$championship, $category] = finishedChampionship();
        $championship->archive($this->admin);

        $athlete = $category->athletes()->first();

        expect(fn () => Livewire::test(WeighIn::class, ['ageCategory' => $category->ageCategory])
            ->set("weights.{$athlete->id}", '64.8')
            ->call('record', $athlete->id))
            ->toThrow(ChampionshipArchivedException::class);
    });
});

describe('reopening', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('needs a reason', function () {
        [$championship] = finishedChampionship();
        $championship->archive($this->admin);

        Livewire::test(Archive::class)
            ->call('confirmReopen', $championship->id)
            ->set('reopenReason', '   ')
            ->call('reopen', $championship->id)
            ->assertHasErrors('reopenReason');

        expect($championship->refresh()->isArchived())->toBeTrue();
    });

    it('records who reopened it and why', function () {
        [$championship, $category] = finishedChampionship();
        $championship->archive($this->admin);

        Livewire::test(Archive::class)
            ->call('confirmReopen', $championship->id)
            ->set('reopenReason', 'Transcription error in the final')
            ->call('reopen', $championship->id);

        $championship->refresh();
        $event = $championship->events()->where('action', 'reopened')->first();

        expect($championship->isArchived())->toBeFalse()
            ->and($event->note)->toBe('Transcription error in the final')
            ->and($event->user_id)->toBe($this->admin->id);

        // And editing works again.
        $athlete = $category->athletes()->first();
        $athlete->update(['fullname' => 'Corrected Name']);

        expect($athlete->refresh()->fullname)->toBe('Corrected Name');
    });

    it('keeps the archiving on the record after reopening', function () {
        [$championship] = finishedChampionship();

        $championship->archive($this->admin);
        $championship->reopen($this->admin, 'Scoring dispute');

        expect($championship->events()->count())->toBe(2)
            ->and($championship->events()->pluck('action')->sort()->values()->all())
            ->toBe(['archived', 'reopened']);
    });
});
