<?php

use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Courts;
use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\MatControl;
use App\Livewire\Competition\Scoreboard;
use App\Livewire\Scoreboard\Viewer;
use App\Livewire\Settings\Accounts;
use App\Models\Bout;
use App\Models\Court;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $this->viewer = User::factory()->scoreboardViewer()->create();
});

describe('the admin creates the accounts', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('creates a scoreboard viewer with only what that role carries', function () {
        Livewire::test(Accounts::class)
            ->set('name', 'Coaches Room')
            ->set('email', 'coaches@example.test')
            ->set('role', User::ROLE_SCOREBOARD_VIEWER)
            ->set('password', 'a-long-enough-password')
            ->call('save')
            ->assertHasNoErrors();

        $account = User::where('email', 'coaches@example.test')->firstOrFail();

        expect($account->role)->toBe(User::ROLE_SCOREBOARD_VIEWER)
            ->and($account->is_active)->toBeTrue()
            ->and($account->canViewScoreboard())->toBeTrue()
            ->and($account->canManageCompetition())->toBeFalse()
            ->and($account->canManageUsers())->toBeFalse()
            ->and(Hash::check('a-long-enough-password', $account->password))->toBeTrue();
    });

    it('creates an operator account too', function () {
        Livewire::test(Accounts::class)
            ->set('name', 'Mat Operator')
            ->set('email', 'operator@example.test')
            ->set('role', User::ROLE_OFFICIAL)
            ->set('password', 'a-long-enough-password')
            ->call('save')
            ->assertHasNoErrors();

        expect(User::where('email', 'operator@example.test')->firstOrFail()->role)->toBe(User::ROLE_OFFICIAL);
    });

    /**
     * The allowlist is the point: a role arrives from a browser like any other
     * field, and an account that can mint accounts is not something a form is
     * allowed to make.
     */
    it('refuses a role that is not on the allowlist', function () {
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPERVISOR, 'root'] as $role) {
            Livewire::test(Accounts::class)
                ->set('name', 'Sneaky')
                ->set('email', "sneak-{$role}@example.test")
                ->set('role', $role)
                ->set('password', 'a-long-enough-password')
                ->call('save')
                ->assertHasErrors('role');
        }

        expect(User::where('email', 'like', 'sneak-%')->count())->toBe(0);
    });

    it('refuses a duplicate email', function () {
        Livewire::test(Accounts::class)
            ->set('name', 'Copy')
            ->set('email', $this->viewer->email)
            ->set('role', User::ROLE_SCOREBOARD_VIEWER)
            ->set('password', 'a-long-enough-password')
            ->call('save')
            ->assertHasErrors('email');
    });

    it('deactivates and reactivates an account', function () {
        Livewire::test(Accounts::class)->call('toggleActive', $this->viewer->id);
        expect($this->viewer->refresh()->is_active)->toBeFalse();

        Livewire::test(Accounts::class)->call('toggleActive', $this->viewer->id);
        expect($this->viewer->refresh()->is_active)->toBeTrue();
    });

    /** An admin account is not editable through the account form at all. */
    it('will not touch an admin account through this form', function () {
        $other = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // The query the component runs is itself scoped to the assignable
        // roles, so an admin id does not resolve to a row at all.
        expect(fn () => Livewire::test(Accounts::class)->call('toggleActive', $other->id))
            ->toThrow(ModelNotFoundException::class);

        expect($other->refresh()->is_active)->toBeTrue();
    });
});

describe('who may manage accounts', function () {
    it('is closed to everybody but an admin', function () {
        foreach ([$this->viewer, User::factory()->official()->create(), User::factory()->create(['role' => User::ROLE_VIEWER])] as $user) {
            $this->actingAs($user)->get(route('accounts.index'))->assertForbidden();
        }

        $this->actingAs($this->admin)->get(route('accounts.index'))->assertOk();
    });

    it('refuses the component call as well as the page', function () {
        $this->actingAs($this->viewer);

        Livewire::test(Accounts::class)->assertForbidden();
    });
});

describe('signing in', function () {
    it('sends a scoreboard viewer to the scoreboard, not the dashboard', function () {
        $account = User::factory()->scoreboardViewer()->create(['password' => 'a-long-enough-password']);

        $this->post(route('login.store'), [
            'email' => $account->email,
            'password' => 'a-long-enough-password',
        ])->assertRedirect(route('scoreboard.index'));
    });

    it('keeps sending everybody else to the dashboard', function () {
        $account = User::factory()->official()->create(['password' => 'a-long-enough-password']);

        $this->post(route('login.store'), [
            'email' => $account->email,
            'password' => 'a-long-enough-password',
        ])->assertRedirect(route('dashboard'));
    });
});

describe('what a scoreboard viewer can reach', function () {
    beforeEach(function () {
        [$this->court, $this->bout] = boutOnMat();
        $this->actingAs($this->viewer);
    });

    it('opens the scoreboard picker and a board', function () {
        $this->get(route('scoreboard.index'))->assertOk();
        $this->get(route('scoreboard.show', $this->court))->assertOk();
    });

    it('sees the live contest and the next bout', function () {
        $next = Bout::where('championship_id', $this->court->championship_id)
            ->readyToFight()
            ->whereKeyNot($this->bout->getKey())
            ->firstOrFail();

        $next->update(['court_id' => $this->court->id, 'fight_number' => 44, 'status' => Bout::STATUS_SCHEDULED]);

        Livewire::test(Scoreboard::class, ['court' => $this->court->refresh()])
            ->assertSee($this->bout->athleteA->fullname)
            ->assertSee('No.44')
            ->assertSee('Read only');
    });

    it('is told the board is read only', function () {
        expect(Livewire::test(Scoreboard::class, ['court' => $this->court])->viewData('readOnly'))->toBeTrue();
    });

    it('receives an update without asking for one', function () {
        // The poll re-renders the same component; the state it reports is the
        // server's, and the viewer never sends any.
        Livewire::test(Scoreboard::class, ['court' => $this->court])
            ->assertSee($this->bout->athleteB->fullname)
            ->call('$refresh')
            ->assertSee($this->bout->athleteB->fullname);
    });
});

describe('what a scoreboard viewer cannot reach', function () {
    beforeEach(function () {
        [$this->court, $this->bout] = boutOnMat();
        $this->category = $this->bout->weightCategory;
        $this->championship = $this->court->championship;
        $this->actingAs($this->viewer);
    });

    it('is refused every competition screen', function () {
        foreach ([
            route('dashboard'),
            route('championships.index'),
            route('championships.show', $this->championship),
            route('entries.index', $this->championship),
            route('fight-order.index', $this->championship),
            route('courts.index', $this->championship),
            route('mats.live', $this->court),
            route('bracket.show', $this->category),
            route('archive.index'),
            route('medals.index', $this->championship),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    });

    it('is refused the exports as well as the screens', function () {
        $this->get(route('exports.fight-order', ['championship' => $this->championship, 'format' => 'pdf']))
            ->assertForbidden();
    });

    /**
     * The heart of it: these are the calls a forged request would make, and
     * every one of them is refused by the server rather than by a hidden
     * button.
     */
    it('is refused every mutation it could forge', function () {
        $calls = [
            [MatControl::class, ['court' => $this->court], 'score', ['khalol', 'a', 120]],
            [MatControl::class, ['court' => $this->court], 'voidLast', []],
            [MatControl::class, ['court' => $this->court], 'publishClock', [90, true]],
            [MatControl::class, ['court' => $this->court], 'finishOnTime', []],
            [MatControl::class, ['court' => $this->court], 'awardDecision', ['a']],
            [MatControl::class, ['court' => $this->court], 'bringOn', [$this->bout->id]],
            [Bracket::class, ['weightCategory' => $this->category], 'saveDraws', []],
            [Bracket::class, ['weightCategory' => $this->category], 'drawAtRandom', []],
            [Bracket::class, ['weightCategory' => $this->category], 'generate', []],
            [Bracket::class, ['weightCategory' => $this->category], 'deleteBracket', []],
            [Bracket::class, ['weightCategory' => $this->category], 'recordResult', [$this->bout->id, 'a']],
            [Bracket::class, ['weightCategory' => $this->category], 'sendToMat', [$this->bout->id, $this->court->id]],
            [FightOrder::class, ['championship' => $this->championship], 'schedule', []],
            [FightOrder::class, ['championship' => $this->championship], 'clear', []],
            [FightOrder::class, ['championship' => $this->championship], 'sendToMat', [$this->bout->id, $this->court->id]],
            [Courts::class, ['championship' => $this->championship], 'toggleActive', [$this->court->id]],
            [Courts::class, ['championship' => $this->championship], 'delete', [$this->court->id]],
        ];

        foreach ($calls as [$component, $params, $method, $args]) {
            Livewire::test($component, $params)
                ->call($method, ...$args)
                ->assertForbidden();
        }
    });

    it('leaves the contest exactly as it was', function () {
        $before = $this->bout->only(['winner_athlete_id', 'status', 'court_id', 'clock_seconds_left']);

        Livewire::test(MatControl::class, ['court' => $this->court])
            ->call('score', 'khalol', 'a', 100)
            ->assertForbidden();

        expect($this->bout->refresh()->only(array_keys($before)))->toBe($before)
            ->and($this->bout->events()->count())->toBe(0);
    });
});

describe('scope', function () {
    it('refuses a mat outside the account scope, however the id arrives', function () {
        [$mine] = boutOnMat();
        [$theirs] = boutOnMat();

        $scoped = User::factory()->scoreboardViewer($mine->championship)->create();

        $this->actingAs($scoped);

        $this->get(route('scoreboard.show', $mine))->assertOk();
        $this->get(route('scoreboard.show', $theirs))->assertForbidden();

        // And through the public display route, which takes the same id.
        config()->set('display.public', true);
        $this->get(route('display.scoreboard', $theirs))->assertForbidden();
    });

    it('never puts another championship\'s mats in the payload', function () {
        [$mine] = boutOnMat();
        [$theirs] = boutOnMat();

        // Named explicitly: the factory draws from a small pool of titles, and
        // two championships sharing one would make this assertion meaningless.
        $mine->championship->update(['title' => 'Mine Championship']);
        $theirs->championship->update(['title' => 'Theirs Championship']);

        $scoped = User::factory()->scoreboardViewer($mine->championship->refresh())->create();

        $this->actingAs($scoped);

        Livewire::test(Viewer::class)
            ->assertSee('Mine Championship')
            ->assertDontSee('Theirs Championship');
    });
});

describe('a closed account', function () {
    it('is turned away from every page, scoreboard included', function () {
        $closed = User::factory()->scoreboardViewer()->inactive()->create();
        [$court] = boutOnMat();

        $this->actingAs($closed)->get(route('scoreboard.index'))->assertRedirect(route('login'));
        $this->actingAs($closed)->get(route('scoreboard.show', $court))->assertRedirect(route('login'));
    });

    it('loses its competition rights the moment it is closed', function () {
        $closed = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => false]);

        expect($closed->canManageCompetition())->toBeFalse()
            ->and($closed->canManageUsers())->toBeFalse()
            ->and($closed->canViewScoreboard())->toBeFalse();
    });
});

describe('who else may read a board', function () {
    it('lets an operator and an admin in through the same permission', function () {
        [$court] = boutOnMat();

        foreach ([$this->admin, User::factory()->official()->create()] as $user) {
            $this->actingAs($user)->get(route('scoreboard.show', $court))->assertOk();
        }
    });

    /** Reading a board grants nothing towards scoring on one. */
    it('does not let an operator score merely because they can watch', function () {
        [$court] = boutOnMat();
        $operator = User::factory()->official()->create();

        expect($operator->canViewScoreboard())->toBeTrue()
            ->and($operator->canManageCompetition())->toBeFalse();

        $this->actingAs($operator);

        Livewire::test(MatControl::class, ['court' => $court])
            ->call('score', 'khalol', 'a', 100)
            ->assertForbidden();
    });
});

describe('the dedicated layout', function () {
    /**
     * The point of the whole thing: the sidebar is not hidden for this
     * account, it is never rendered. Asserted on the response body, because
     * that is where a determined viewer would go looking.
     */
    it('renders no application chrome for a scoreboard viewer', function () {
        [$court] = boutOnMat();

        $body = $this->actingAs($this->viewer)->get(route('scoreboard.show', $court))->getContent();

        expect($body)->toContain('data-layout="scoreboard-viewer"')
            ->and($body)->not->toContain('data-flux-sidebar')
            ->and($body)->not->toContain(route('dashboard'))
            ->and($body)->not->toContain(route('championships.index'))
            ->and($body)->not->toContain(route('archive.index'))
            ->and($body)->not->toContain(route('profile.edit'))
            ->and($body)->not->toContain(route('accounts.index'))
            ->and($body)->not->toContain(route('courts.index', $court->championship));
    });

    it('offers only the controls the role is allowed', function () {
        [$court] = boutOnMat();

        $this->actingAs($this->viewer)->get(route('scoreboard.show', $court))
            ->assertOk()
            ->assertSee('Read only')
            ->assertSee('Fullscreen')
            ->assertSee('Sign out')
            ->assertDontSee('Bring on')
            ->assertDontSee('Take back last call')
            ->assertDontSee('Halal');
    });

    it('leaves the admin and operator shells alone', function () {
        foreach ([$this->admin, User::factory()->official()->create()] as $user) {
            $body = $this->actingAs($user)->get(route('dashboard'))->getContent();

            expect($body)->toContain('data-flux-sidebar')
                ->and($body)->not->toContain('data-layout="scoreboard-viewer"');
        }
    });
});

describe('choosing a mat', function () {
    it('goes straight to the only mat there is', function () {
        [$court] = boutOnMat();
        $scoped = User::factory()->scoreboardViewer($court->championship)->create();

        expect(Livewire::actingAs($scoped)->test(Viewer::class)->get('courtId'))->toBe($court->id);
    });

    it('asks which one when there are several', function () {
        [$first] = boutOnMat();
        $second = Court::factory()->create(['championship_id' => $first->championship_id, 'number' => 2]);

        $scoped = User::factory()->scoreboardViewer($first->championship)->create();

        Livewire::actingAs($scoped)->test(Viewer::class)
            ->assertSet('courtId', null)
            ->assertSee('Choose a mat')
            ->call('selectMat', $second->id)
            ->assertSet('courtId', $second->id);
    });

    it('says so when the account has no mat at all', function () {
        Livewire::actingAs($this->viewer)->test(Viewer::class)
            ->assertSee('No mats available')
            ->assertSee('not assigned to a scoreboard yet');
    });

    /** The selector is a view of a scoped query, never a list to be trusted back. */
    it('refuses a forged mat id', function () {
        [$mine] = boutOnMat();
        [$theirs] = boutOnMat();

        $scoped = User::factory()->scoreboardViewer($mine->championship)->create();

        Livewire::actingAs($scoped)->test(Viewer::class)
            ->call('selectMat', $theirs->id)
            ->assertForbidden();
    });

    it('drops a selection that stops being allowed', function () {
        [$first] = boutOnMat();
        $second = Court::factory()->create(['championship_id' => $first->championship_id, 'number' => 2]);

        $scoped = User::factory()->scoreboardViewer($first->championship)->create();

        $board = Livewire::actingAs($scoped)->test(Viewer::class)->call('selectMat', $second->id);

        $second->update(['is_active' => false]);

        // The mat is gone from the scoped query, so the board falls back to
        // the one that is left rather than rendering a mat it may not show.
        $board->call('$refresh')->assertSet('courtId', $first->id);
    });
});
