<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Categories;
use App\Livewire\Competition\Courts;
use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\MatControl;
use App\Livewire\Referee\Mats as RefereeMats;
use App\Livewire\Scoreboard\Viewer as ScoreboardViewer;
use App\Livewire\Settings\Accounts;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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

    // Assigned to the mat they work. The role alone is no longer access —
    // §30.7 — so a referee without this reaches nothing, which the tests below
    // check on its own.
    $this->referee = User::factory()->referee()->create();
    $this->referee->courts()->attach($this->court);
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
            // Created with no mat, so it can score nothing yet. The role says
            // what kind of work the account does; the assignment says where.
            ->and($account->courts()->count())->toBe(0)
            ->and($account->mayRefereeCourt($this->court))->toBeFalse()
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
            route('athletes.index', ['championship' => $this->championship, 'competition' => 'M']),
            route('weighin.index', ['championship' => $this->championship, 'competition' => 'M']),
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

        // And the mat it was assigned to authorises nothing while it is closed,
        // so the assignment surviving deactivation costs nothing.
        $gate = Gate::forUser($this->referee->fresh());

        expect($gate->allows('score-bout', $this->court))->toBeFalse()
            ->and($this->bout->refresh()->winner_athlete_id)->toBeNull();
    });
});

describe('mat isolation', function () {
    beforeEach(function () {
        // A second mat, in the same championship, that this referee is not
        // assigned to — the case a championship-wide scope would have let
        // through.
        [$this->otherCourt, $this->otherBout] = boutOnMat();

        $this->actingAs($this->referee);
    });

    it('is offered only the mats it is assigned to', function () {
        Livewire::test(RefereeMats::class)
            ->assertSee($this->court->label())
            ->assertDontSee($this->otherCourt->label());
    });

    /** §30.4 — the address bar is the obvious way round a menu. */
    it('is refused another mat by typing its address', function () {
        $this->get(route('mats.live', $this->court))->assertOk();
        $this->get(route('mats.live', $this->otherCourt))->assertForbidden();
    });

    /**
     * The gate itself, which is what the route and all thirteen writes on the
     * mat screen read. Checking it directly is what proves the refusal is one
     * rule rather than a check repeated in fourteen places.
     */
    it('fails the gate for another mat, however the request arrives', function () {
        $gate = Gate::forUser($this->referee);

        expect($gate->allows('mat.view', $this->court))->toBeTrue()
            ->and($gate->allows('score-bout', $this->court))->toBeTrue()
            ->and($gate->allows('mat.view', $this->otherCourt))->toBeFalse()
            ->and($gate->allows('score-bout', $this->otherCourt))->toBeFalse()
            ->and($this->referee->mayRefereeCourt($this->otherCourt))->toBeFalse();
    });

    it('is refused another mat board', function () {
        $this->get(route('scoreboard.show', $this->court))->assertOk();
        $this->get(route('scoreboard.show', $this->otherCourt))->assertForbidden();
        $this->get(route('display.scoreboard', $this->otherCourt))->assertForbidden();
    });

    it('cannot pick another mat from the board selector', function () {
        Livewire::test(ScoreboardViewer::class)
            ->call('selectMat', $this->otherCourt->id)
            ->assertForbidden();
    });

    /**
     * A contest standing on somebody else's mat belongs to whoever is running
     * it. Pulling it across would take it out from under them mid-session.
     */
    it('cannot pull a contest off another mat onto its own', function () {
        Livewire::test(MatControl::class, ['court' => $this->court])
            ->call('bringOn', $this->otherBout->id)
            ->assertSee(__('That contest is not available to this mat.'));

        expect($this->otherBout->refresh()->court_id)->toBe($this->otherCourt->id);
    });

    it('leaves the other mat untouched throughout', function () {
        expect($this->otherBout->refresh()->events()->count())->toBe(0)
            ->and($this->otherBout->winner_athlete_id)->toBeNull();
    });
});

describe('an unassigned referee', function () {
    beforeEach(function () {
        $this->unassigned = User::factory()->referee()->create();
        $this->actingAs($this->unassigned);
    });

    /**
     * §30.7 — the role is not access on its own. An account created and not
     * yet given a mat has to refuse rather than open every mat in the venue,
     * because the failure mode of the other default is silent.
     */
    it('reaches no mat at all', function () {
        $this->get(route('mats.live', $this->court))->assertForbidden();

        $gate = Gate::forUser($this->unassigned);

        expect($gate->allows('mat.view', $this->court))->toBeFalse()
            ->and($gate->allows('score-bout', $this->court))->toBeFalse();
    });

    it('is told why its landing page is empty', function () {
        $this->get(route('referee.mats'))
            ->assertOk()
            ->assertSee(__('No mat assigned'))
            ->assertDontSee($this->court->label());
    });
});

describe('the admin manages the assignment', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('assigns a mat when the account is created', function () {
        Livewire::test(Accounts::class)
            ->set('name', 'Mat 1 Referee')
            ->set('email', 'mat1@example.test')
            ->set('role', User::ROLE_REFEREE)
            ->set('courtIds', [(string) $this->court->id])
            ->set('password', 'a-long-enough-password')
            ->call('save')
            ->assertHasNoErrors();

        $account = User::where('email', 'mat1@example.test')->firstOrFail();

        expect($account->courts->pluck('id')->all())->toBe([$this->court->id])
            ->and($account->mayRefereeCourt($this->court))->toBeTrue();
    });

    it('revokes a mat by unticking it', function () {
        Livewire::test(Accounts::class)
            ->call('edit', $this->referee->id)
            ->assertSet('courtIds', [(string) $this->court->id])
            ->set('courtIds', [])
            ->call('save')
            ->assertHasNoErrors();

        expect($this->referee->refresh()->courts()->count())->toBe(0);

        // And it bites on the next request rather than at the next sign-in.
        $this->actingAs($this->referee);
        $this->get(route('mats.live', $this->court))->assertForbidden();
    });

    it('refuses a mat that does not exist', function () {
        Livewire::test(Accounts::class)
            ->set('name', 'Ghost Referee')
            ->set('email', 'ghost@example.test')
            ->set('role', User::ROLE_REFEREE)
            ->set('courtIds', ['999999'])
            ->set('password', 'a-long-enough-password')
            ->call('save')
            ->assertHasErrors('courtIds.0');

        expect(User::where('email', 'ghost@example.test')->exists())->toBeFalse();
    });

    /** Mats belong to referees. Handing one to an operator would mean nothing. */
    it('drops any mats ticked for a role that does not use them', function () {
        Livewire::test(Accounts::class)
            ->set('name', 'Desk Operator')
            ->set('email', 'desk@example.test')
            ->set('role', User::ROLE_OFFICIAL)
            ->set('courtIds', [(string) $this->court->id])
            ->set('password', 'a-long-enough-password')
            ->call('save')
            ->assertHasNoErrors();

        expect(User::where('email', 'desk@example.test')->firstOrFail()->courts()->count())->toBe(0);
    });

    it('shows which mats each referee works', function () {
        Livewire::test(Accounts::class)->assertSee($this->court->label());
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
