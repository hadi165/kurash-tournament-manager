<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Categories;
use App\Livewire\Competition\Courts;
use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\MatControl;
use App\Livewire\Referee\Mats as RefereeMats;
use App\Livewire\Settings\Accounts;
use App\Models\User;
use Livewire\Livewire;

/**
 * The referee account.
 *
 * Two claims, and the second matters more than the first: a referee can score
 * the contest in front of them, and a referee can reach nothing else. Every
 * refusal below is the server's, made against a call a forged request could
 * make — not a hidden button.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

    [$this->court, $this->bout] = boutOnMat();
    $this->championship = $this->court->championship;
    $this->category = $this->bout->weightCategory;

    $this->referee = User::factory()->referee()->create();
});

describe('the admin creates the account', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('creates a referee carrying exactly what that role carries', function () {
        Livewire::test(Accounts::class)
            ->set('name', 'Mat 1 Referee')
            ->set('email', 'referee@example.test')
            ->set('role', User::ROLE_REFEREE)
            ->set('password', 'a-long-enough-password')
            ->call('save')
            ->assertHasNoErrors();

        $account = User::where('email', 'referee@example.test')->firstOrFail();

        expect($account->role)->toBe(User::ROLE_REFEREE)
            ->and($account->canScoreBouts())->toBeTrue()
            ->and($account->canViewScoreboard())->toBeTrue()
            // The separation the role exists for: scoring a contest grants
            // nothing towards running the competition it sits in.
            ->and($account->canManageCompetition())->toBeFalse()
            ->and($account->canManageUsers())->toBeFalse();
    });
});

describe('what a referee reaches', function () {
    beforeEach(fn () => $this->actingAs($this->referee));

    it('opens the mats it may work and the mat screen itself', function () {
        $this->get(route('referee.mats'))->assertOk()->assertSee($this->court->label());
        $this->get(route('mats.live', $this->court))->assertOk();
    });

    it('opens the score board', function () {
        $this->get(route('scoreboard.index'))->assertOk();
        $this->get(route('scoreboard.show', $this->court))->assertOk();
    });

    it('scores the contest in front of it', function () {
        Livewire::test(MatControl::class, ['court' => $this->court])
            ->call('score', 'yonbosh', 'a', 200)
            ->assertHasNoErrors();

        $this->bout->refresh();

        expect($this->bout->events()->count())->toBe(1)
            ->and($this->bout->events()->first()->user_id)->toBe($this->referee->id);
    });

    it('declares a winner and ends the contest', function () {
        Livewire::test(MatControl::class, ['court' => $this->court])->call('declareWinner', 'b');

        expect($this->bout->refresh()->winner_athlete_id)->toBe($this->bout->athlete_b_id);
    });

    it('lands on its mats after signing in rather than on a dashboard it cannot open', function () {
        auth()->logout();

        $this->post(route('login'), [
            'email' => $this->referee->email,
            'password' => 'password',
        ])->assertRedirect(route('referee.mats'));
    });

    it('is sent to its mats from the front door', function () {
        $this->get('/')->assertRedirect(route('referee.mats'));
    });
});

describe('what a referee is refused', function () {
    beforeEach(fn () => $this->actingAs($this->referee));

    it('is refused every competition screen', function () {
        foreach ([
            route('dashboard'),
            route('archive.index'),
            route('championships.index'),
            route('championships.show', $this->championship),
            route('entries.index', $this->championship),
            route('medals.index', $this->championship),
            route('courts.index', $this->championship),
            route('brackets.index', $this->championship),
            route('fight-order.index', $this->championship),
            route('bracket.show', $this->category),
            route('athletes.index', $this->championship->ageCategories()->first()),
            route('weighin.index', $this->championship->ageCategories()->first()),
            route('operator.draws.index'),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    });

    it('is refused the exports', function () {
        $this->get(route('exports.fight-order', ['championship' => $this->championship, 'format' => 'pdf']))
            ->assertForbidden();
    });

    /**
     * The calls a forged request would make against the screens the role does
     * not carry. Scoring passes; everything that changes the competition around
     * the contest does not.
     */
    it('is refused every mutation outside the mat', function () {
        $calls = [
            [Bracket::class, ['weightCategory' => $this->category], 'generate', []],
            [Bracket::class, ['weightCategory' => $this->category], 'drawAtRandom', []],
            [Bracket::class, ['weightCategory' => $this->category], 'deleteBracket', []],
            [Bracket::class, ['weightCategory' => $this->category], 'recordResult', [$this->bout->id, 'a']],
            [FightOrder::class, ['championship' => $this->championship], 'schedule', []],
            [FightOrder::class, ['championship' => $this->championship], 'clear', []],
            [Courts::class, ['championship' => $this->championship], 'toggleActive', [$this->court->id]],
            [Categories::class, ['championship' => $this->championship], 'delete', [1]],
        ];

        foreach ($calls as [$component, $params, $method, $args]) {
            Livewire::test($component, $params)
                ->call($method, ...$args)
                ->assertForbidden();
        }
    });

    it('is refused the account screen', function () {
        Livewire::test(Accounts::class)->assertForbidden();
    });

    it('authorises nothing once the account is closed', function () {
        // Assigned rather than mass-updated: is_active is deliberately absent
        // from the model's fillable list, so update() would silently do
        // nothing and the test would pass by not testing anything.
        $this->referee->is_active = false;
        $this->referee->save();

        // A closed account is signed out on its next request rather than shown
        // a refusal it could keep clicking past.
        $this->get(route('mats.live', $this->court))->assertRedirect(route('login'));

        $this->actingAs($this->referee);

        Livewire::test(MatControl::class, ['court' => $this->court])
            ->call('score', 'khalol', 'a', 100)
            ->assertForbidden();

        expect($this->bout->refresh()->winner_athlete_id)->toBeNull();
    });
});

describe('scope', function () {
    it('is offered only the mats of the championship it belongs to', function () {
        [$theirs] = boutOnMat();

        $scoped = User::factory()->referee($this->championship)->create();

        $this->actingAs($scoped);

        Livewire::test(RefereeMats::class)
            ->assertSee($this->court->label())
            ->assertDontSee($theirs->label());

        $this->get(route('scoreboard.show', $theirs))->assertForbidden();
    });
});

describe('everyone else', function () {
    /**
     * The mat screen stayed open to the people who work the competition. An
     * official following a session needs it in front of them, and took nothing
     * away from anybody by having it — the buttons are a separate permission.
     */
    it('still lets an official watch a mat without scoring on it', function () {
        $official = User::factory()->official()->create();

        $this->actingAs($official);

        $this->get(route('mats.live', $this->court))->assertOk();

        Livewire::test(MatControl::class, ['court' => $this->court])
            ->call('score', 'khalol', 'a', 100)
            ->assertForbidden();

        expect($this->bout->refresh()->winner_athlete_id)->toBeNull();
    });

    it('keeps a scoreboard viewer off the mat screen entirely', function () {
        $this->actingAs(User::factory()->scoreboardViewer()->create());

        $this->get(route('mats.live', $this->court))->assertForbidden();
        $this->get(route('referee.mats'))->assertForbidden();
    });
});
