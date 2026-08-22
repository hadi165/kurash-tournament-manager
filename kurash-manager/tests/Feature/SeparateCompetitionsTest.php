<?php

use App\Exports\FightOrderReport;
use App\Livewire\Competition\Brackets;
use App\Livewire\Competition\Courts;
use App\Livewire\Competition\Entries;
use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\Medals;
use App\Livewire\Competition\Registration;
use App\Livewire\Competition\WeighIn;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use App\Services\MedalTable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->actingAs($this->admin);

    $this->championship = Championship::factory()->create(['title' => 'Asian Kurash 2026']);

    // The situation this is all about: the same weight label in two
    // competitions that have nothing to do with each other.
    $this->men = AgeCategory::factory()->for($this->championship)->create(['gender' => 'M', 'age_group' => 'Senior', 'sort_order' => 0]);
    $this->women = AgeCategory::factory()->for($this->championship)->create(['gender' => 'F', 'age_group' => 'Senior', 'sort_order' => 1]);

    $this->mens66 = WeightCategory::factory()->create([
        'age_category_id' => $this->men->id, 'label' => '-66', 'gender' => 'M',
    ]);

    $this->womens66 = WeightCategory::factory()->create([
        'age_category_id' => $this->women->id, 'label' => '-66', 'gender' => 'F',
    ]);

    $this->seat = function (WeightCategory $category, int $count, string $gender, string $prefix) {
        foreach (range(1, $count) as $draw) {
            Athlete::factory()->drawn($draw)->create([
                'championship_id' => $this->championship->id,
                'age_category_id' => $category->age_category_id,
                'weight_category_id' => $category->id,
                'gender' => $gender,
                'fullname' => "{$prefix} {$draw}",
                'weighin_status' => 'pass',
            ]);
        }
    };
});

describe('the two -66 kg classes are two competitions', function () {
    it('names each one so a reader can tell them apart', function () {
        expect($this->mens66->exportName())->toBe('Male -66')
            ->and($this->womens66->exportName())->toBe('Female -66')
            // Same label, different identity — the id is what everything keys on.
            ->and($this->mens66->label)->toBe($this->womens66->label)
            ->and($this->mens66->id)->not->toBe($this->womens66->id);
    });

    it('counts entries separately', function () {
        ($this->seat)($this->mens66, 13, 'M', 'Man');
        ($this->seat)($this->womens66, 8, 'F', 'Woman');

        $rows = collect(Livewire::test(Entries::class, ['championship' => $this->championship])->viewData('byWeight'));

        $men = $rows->firstWhere(fn (array $r) => $r['category']->id === $this->mens66->id);
        $women = $rows->firstWhere(fn (array $r) => $r['category']->id === $this->womens66->id);

        expect($men['cleared'])->toBe(13)
            ->and($women['cleared'])->toBe(8);
    });

    /** The heart of it: two draws, two bucket sizes, no shared athlete. */
    it('draws them independently, with their own buckets and byes', function () {
        ($this->seat)($this->mens66, 13, 'M', 'Man');
        ($this->seat)($this->womens66, 8, 'F', 'Woman');

        app(BracketGenerator::class)->generate($this->mens66);
        app(BracketGenerator::class)->generate($this->womens66);

        $men = $this->mens66->refresh();
        $women = $this->womens66->refresh();

        expect($men->draw_bucket_size)->toBe(16)
            ->and($men->draw_bye_count)->toBe(3)
            ->and($women->draw_bucket_size)->toBe(8)
            ->and($women->draw_bye_count)->toBe(0);

        // Nobody from one class is seated in the other's bracket.
        $womenIds = $women->athletes()->pluck('id');

        expect($men->bouts()->whereIn('athlete_a_id', $womenIds)->exists())->toBeFalse()
            ->and($men->bouts()->whereIn('athlete_b_id', $womenIds)->exists())->toBeFalse();
    });

    it('keeps the podiums apart', function () {
        ($this->seat)($this->mens66, 4, 'M', 'Man');
        ($this->seat)($this->womens66, 4, 'F', 'Woman');

        foreach ([$this->mens66, $this->womens66] as $category) {
            app(BracketGenerator::class)->generate($category);
            runTournament($category);
        }

        $medals = app(MedalTable::class);
        $men = $medals->forCategory($this->mens66->refresh());
        $women = $medals->forCategory($this->womens66->refresh());

        expect($men['gold']->gender)->toBe('M')
            ->and($women['gold']->gender)->toBe('F')
            ->and($men['gold']->id)->not->toBe($women['gold']->id);
    });
});

describe('the running order says which competition', function () {
    beforeEach(function () {
        ($this->seat)($this->mens66, 4, 'M', 'Man');
        ($this->seat)($this->womens66, 4, 'F', 'Woman');

        foreach ([$this->mens66, $this->womens66] as $category) {
            app(BracketGenerator::class)->generate($category);
        }

        app(FightOrderScheduler::class)->schedule($this->championship);
    });

    it('labels every row with its class and division', function () {
        Livewire::test(FightOrder::class, ['championship' => $this->championship])
            ->assertSee('Male -66')
            ->assertSee('Female -66')
            ->assertSee('Men Senior')
            ->assertSee('Women Senior');
    });

    /** Filtered in the query: the other competition is not in the payload. */
    it('narrows to one competition without carrying the other', function () {
        Livewire::test(FightOrder::class, ['championship' => $this->championship])
            ->set('competition', 'F')
            ->assertSee('Female -66')
            ->assertDontSee('Man 1')
            ->set('competition', 'M')
            ->assertSee('Male -66')
            ->assertDontSee('Woman 1');
    });

    /**
     * A championship can run "Men Junior" beside "Men Senior", and a table
     * official working the men's mat wants both without reading past the
     * women's — so the scope is the competition, not a single division.
     */
    it('keeps every age group of the competition it is reading', function () {
        Livewire::test(FightOrder::class, ['championship' => $this->championship])
            ->set('competition', 'M')
            ->assertSee('Men Senior')
            ->assertDontSee('Women Senior');
    });

    /**
     * The way back out. The sidebar can take you into a competition but not
     * out of it, so the screen says what it is narrowed to and offers the
     * link that widens it again.
     */
    it('says what it is narrowed to, and offers the way out', function () {
        Livewire::test(FightOrder::class, ['championship' => $this->championship])
            ->assertDontSee('Showing this competition only.')
            ->set('competition', 'M')
            ->assertSee('Men')
            ->assertSee('Showing this competition only.')
            ->assertSee('Show all');
    });

    /** The sheet a table official carries away is scoped the same way. */
    it('prints one competition to its own sheet', function () {
        $report = new FightOrderReport($this->championship, 'M');
        $names = array_column($report->rows(), 4);

        expect($names)->not->toBeEmpty()
            ->and(collect($names)->every(fn ($name) => ! str_starts_with($name, 'Woman ')))->toBeTrue()
            ->and($report->meta()['Classes'])->toBe('Men')
            ->and($report->filename())->toEndWith('-men');
    });

    it('prints every competition when none is chosen', function () {
        $names = array_column((new FightOrderReport($this->championship))->rows(), 4);

        expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'Man ')))->toBeTrue()
            ->and(collect($names)->contains(fn ($n) => str_starts_with($n, 'Woman ')))->toBeTrue();
    });

    /** A competition this championship does not run must not narrow the sheet. */
    it('ignores a competition the championship does not run', function () {
        $body = $this->get(route('exports.fight-order', [
            'championship' => $this->championship,
            'format' => 'csv',
            'competition' => 'X',
        ]))->assertOk()->streamedContent();

        expect($body)->toContain('Man 1')->toContain('Woman 1');
    });
});

describe('registration keeps them apart', function () {
    /**
     * The division is what refuses this now. It belongs to one competition, so
     * the entry is wrong before anyone looks at which weight class was picked.
     */
    it('refuses a woman entered into a men\'s division', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->set('fullname', 'Mistaken Entry')
            ->set('noc_code', 'UZB')
            ->set('gender', 'F')
            ->set('weight_category_id', $this->mens66->id)
            ->call('save')
            ->assertHasErrors('gender');

        expect(Athlete::where('fullname', 'Mistaken Entry')->exists())->toBeFalse();
    });

    it('refuses a man entered into a women\'s division', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'F'])
            ->set('fullname', 'Also Mistaken')
            ->set('noc_code', 'UZB')
            ->set('gender', 'M')
            ->set('weight_category_id', $this->womens66->id)
            ->call('save')
            ->assertHasErrors('gender');

        expect(Athlete::where('fullname', 'Also Mistaken')->exists())->toBeFalse();
    });

    it('accepts each of them into their own', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->set('fullname', 'Correct Man')
            ->set('noc_code', 'UZB')
            ->set('gender', 'M')
            ->set('weight_category_id', $this->mens66->id)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'F'])
            ->set('fullname', 'Correct Woman')
            ->set('noc_code', 'UZB')
            ->set('gender', 'F')
            ->set('weight_category_id', $this->womens66->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(Athlete::whereIn('fullname', ['Correct Man', 'Correct Woman'])->count())->toBe(2);
    });

    /**
     * A class from another division cannot be reached by posting its id — the
     * gender here agrees with the division, so the class check is what has to
     * catch it.
     */
    it('refuses a class from another division', function () {
        Livewire::test(Registration::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->set('fullname', 'Forged')
            ->set('noc_code', 'UZB')
            ->set('gender', 'M')
            ->set('weight_category_id', $this->womens66->id)
            ->call('save')
            ->assertHasErrors('weight_category_id');

        expect(Athlete::where('fullname', 'Forged')->exists())->toBeFalse();
    });
});

describe('the weigh-in lists are separate', function () {
    it('shows only the division being weighed', function () {
        ($this->seat)($this->mens66, 3, 'M', 'Man');
        ($this->seat)($this->womens66, 3, 'F', 'Woman');

        Livewire::test(WeighIn::class, ['championship' => $this->championship, 'competition' => 'M'])
            ->assertSee('Man 1')
            ->assertDontSee('Woman 1');

        Livewire::test(WeighIn::class, ['championship' => $this->championship, 'competition' => 'F'])
            ->assertSee('Woman 1')
            ->assertDontSee('Man 1');
    });
});

/**
 * The championship's own screens read one competition at a time.
 *
 * Unlike registration and the weigh-in, these belong to the whole
 * championship — a competition is a way of reading them, so dropping the
 * filter shows everything again.
 */
describe('the championship screens read one competition at a time', function () {
    beforeEach(function () {
        ($this->seat)($this->mens66, 4, 'M', 'Man');
        ($this->seat)($this->womens66, 4, 'F', 'Woman');

        foreach ([$this->mens66, $this->womens66] as $category) {
            app(BracketGenerator::class)->generate($category);
        }
    });

    it('narrows the entries to one competition', function () {
        Livewire::test(Entries::class, ['championship' => $this->championship])
            ->assertSee('Male -66')
            ->assertSee('Female -66')
            ->set('competition', 'M')
            ->assertSee('Male -66')
            ->assertDontSee('Female -66');
    });

    it('narrows the brackets to one competition', function () {
        Livewire::test(Brackets::class, ['championship' => $this->championship])
            ->set('competition', 'F')
            ->assertSee('Female -66')
            ->assertDontSee('Male -66');
    });

    /** Counts, not just rows: a men's entry table counts men. */
    it('counts only the competition being read', function () {
        $men = Livewire::test(Entries::class, ['championship' => $this->championship])
            ->set('competition', 'M')
            ->viewData('totalEntries');

        $both = Livewire::test(Entries::class, ['championship' => $this->championship])
            ->viewData('totalEntries');

        expect($men)->toBe(4)->and($both)->toBe(8);
    });

    /** The medal table lists decided classes, so both have to be fought. */
    it('narrows the medal table to one competition', function () {
        foreach ([$this->mens66, $this->womens66] as $category) {
            runTournament($category);
        }

        Livewire::test(Medals::class, ['championship' => $this->championship])
            ->assertSee('Men Senior')
            ->assertSee('Women Senior')
            ->set('competition', 'M')
            ->assertSee('Men Senior')
            ->assertDontSee('Women Senior');
    });

    /**
     * A mat is physical and belongs to no competition, so it stays listed —
     * what narrows is how much of the competition being read is on it.
     */
    it('counts a mat by the competition being read', function () {
        $court = $this->championship->courts()->create(['number' => 1]);
        $this->mens66->bouts()->update(['court_id' => $court->id]);
        $this->womens66->bouts()->update(['court_id' => $court->id]);

        $mens = $this->championship->bouts()->where('age_category_id', $this->men->id)->count();

        $scoped = Livewire::test(Courts::class, ['championship' => $this->championship])
            ->set('competition', 'M')
            ->viewData('courts')
            ->first();

        expect($scoped->bouts_count)->toBe($mens)
            ->and($mens)->toBeLessThan($court->bouts()->count());
    });

    /**
     * What stands between a mat and being deleted is everything on it, not
     * only the half being read, so the refusal drops the filter.
     */
    it('drops the filter when a delete is refused', function () {
        $court = $this->championship->courts()->create(['number' => 1]);
        $this->womens66->bouts()->update(['court_id' => $court->id]);

        Livewire::test(Courts::class, ['championship' => $this->championship])
            ->set('competition', 'M')
            ->call('delete', $court->id)
            ->assertSet('competition', '')
            ->assertSet('showingBoutsFor', $court->id)
            ->assertSee('Woman 1');
    });
});
