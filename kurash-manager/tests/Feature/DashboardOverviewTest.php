<?php

use App\Livewire\Competition\Dashboard;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Services\BracketGenerator;
use App\Services\FightOrderScheduler;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
});

/**
 * The dashboard exists to say what a competition is waiting on, so these test
 * the blocker it names rather than the numbers beside it.
 */
describe('what needs doing next', function () {
    it('invites the first championship when there are none', function () {
        Livewire::test(Dashboard::class)
            ->assertSee('No competitions yet')
            ->assertSee('Create a championship');
    });

    it('asks for weight classes before anything else', function () {
        Championship::factory()->create(['title' => 'Empty Cup']);

        Livewire::test(Dashboard::class)
            ->assertSee('No weight classes yet')
            ->assertDontSee('has athletes but no bracket');
    });

    it('asks for athletes once the classes exist', function () {
        WeightCategory::factory()->create();

        Livewire::test(Dashboard::class)->assertSee('Nobody is registered yet');
    });

    it('reports a class that has athletes but no bracket', function () {
        $category = drawableClass(4, weighIn: 'pass');

        Livewire::test(Dashboard::class)
            ->assertSee('weight class has athletes but no bracket')
            ->assertSee('Draw -90 kg');
    });

    it('reports athletes still waiting on the scale', function () {
        drawableClass(3, weighIn: 'pending');

        Livewire::test(Dashboard::class)->assertSee('have not been weighed in');
    });

    it('asks for a running order once a bracket is drawn', function () {
        $category = drawableClass(4, weighIn: 'pass');
        app(BracketGenerator::class)->generate($category);

        Livewire::test(Dashboard::class)
            ->assertDontSee('has athletes but no bracket')
            ->assertSee('Build the running order');
    });

    /** Exactly the state that produced "the fight order has no button". */
    it('says when there are no mats to send a bout to', function () {
        $category = drawableClass(4, weighIn: 'pass');
        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($category->ageCategory->championship);

        Livewire::test(Dashboard::class)->assertSee('No mats are set up');
    });

    it('stops nagging once everything is set up', function () {
        $category = drawableClass(4, weighIn: 'pass');
        $championship = $category->ageCategory->championship;

        app(BracketGenerator::class)->generate($category);
        app(FightOrderScheduler::class)->schedule($championship);
        Court::factory()->create(['championship_id' => $championship->id, 'is_active' => true]);

        Livewire::test(Dashboard::class)
            ->assertDontSee('No mats are set up')
            ->assertDontSee('Build the running order')
            ->assertDontSee('has athletes but no bracket');
    });
});

describe('the numbers', function () {
    it('counts athletes, classes and those who passed the scale', function () {
        $category = drawableClass(4, weighIn: 'pass');
        $category->athletes()->limit(1)->update(['weighin_status' => 'fail']);

        Livewire::test(Dashboard::class)
            ->assertSee('Athletes')
            ->assertSee('Passed the scale');

        $summary = Livewire::test(Dashboard::class)->viewData('championships')->first();

        expect($summary['athletes'])->toBe(4)
            ->and($summary['passed'])->toBe(3)
            ->and($summary['classes'])->toBe(1);
    });

    it('reports progress from decided bouts, ignoring byes', function () {
        // 5 athletes: 4 real bouts once the byes are resolved.
        $category = drawableClass(5, weighIn: 'pass');
        app(BracketGenerator::class)->generate($category);

        $summary = Livewire::test(Dashboard::class)->viewData('championships')->first();

        expect($summary['bouts'])->toBe(4)
            ->and($summary['decided'])->toBe(0)
            ->and($summary['progress'])->toBe(0);
    });

    it('shows nothing is live before a bout reaches a mat', function () {
        $category = drawableClass(4, weighIn: 'pass');
        app(BracketGenerator::class)->generate($category);

        $summary = Livewire::test(Dashboard::class)->viewData('championships')->first();

        expect($summary['on_mat'])->toBe(0);
    });
});

it('is reachable by a viewer', function () {
    $this->actingAs(User::factory()->create(['role' => 'viewer']))
        ->get(route('dashboard'))
        ->assertOk();
});

/** A weight class with athletes, ready to be drawn. */
function drawableClass(int $count, string $weighIn): WeightCategory
{
    $ageCategory = AgeCategory::factory()->create();

    $category = WeightCategory::factory()->create([
        'age_category_id' => $ageCategory->id,
        'label' => '-90',
    ]);

    foreach (range(1, $count) as $draw) {
        Athlete::factory()->drawn($draw)->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'weighin_status' => $weighIn,
        ]);
    }

    return $category->refresh();
}
