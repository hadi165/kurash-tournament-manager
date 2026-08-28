<?php

namespace App\Providers;

use App\Contracts\ScoreboardDriver;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Observers\ArchivedChampionshipGuard;
use App\Observers\BoutObserver;
use App\Observers\DisplayContentObserver;
use App\Services\Scoreboard\FakeScoreboardDriver;
use App\Services\Scoreboard\HttpScoreboardDriver;
use App\Services\Scoreboard\NullScoreboardDriver;
use App\Support\DatabaseGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->guardDestructiveCommands();

        // Every write to a bout invalidates the display screens for its
        // championship, whatever caused it.
        Bout::observe(BoutObserver::class);

        // And so does every write that changes what those screens say without
        // touching a bout: a mat renamed or taken out of service, an athlete's
        // name or NOC corrected, a weight class relabelled, the single athlete
        // in a one-entry class placed.
        foreach ([Athlete::class, Court::class, WeightCategory::class] as $model) {
            $model::observe(DisplayContentObserver::class);
        }

        // An archived championship is read-only. Enforced here rather than in
        // each screen, so a mutation path added later inherits the guard
        // instead of having to remember it.
        foreach ([AgeCategory::class, Athlete::class, Bout::class, Court::class, WeightCategory::class] as $model) {
            $model::observe(ArchivedChampionshipGuard::class);
        }
    }

    /**
     * Stop a destructive Artisan command before it reaches a real database.
     *
     * Two mechanisms, because one of them has a hole.
     *
     * The framework prohibition is the load-bearing one. It sets a flag each
     * command checks inside its own handle(), so it holds on every route into
     * the command: a terminal, a queued Artisan::call(), a test, a script.
     *
     * The CommandStarting listener only adds the explanation. It cannot be the
     * enforcement, and finding out why is what this whole exercise turned on:
     * Illuminate\Foundation\Console\Kernel reroutes the Symfony console events
     * only `if (! $this->app->runningUnitTests())`, so a guard built on that
     * event is missing from precisely the place an automated script runs. The
     * third time this database was emptied, it was an automated script.
     *
     * Neither is conditioned on the environment being production. APP_ENV=local
     * on this project is the machine running the competition, with the day's
     * weigh-ins in it. Only a database whose name ends in _test may be
     * destroyed. Plain `migrate` is never touched — an incremental migration
     * must keep working everywhere, and it is not what loses data.
     */
    private function guardDestructiveCommands(): void
    {
        DatabaseGuard::applyCommandProhibitions();

        Event::listen(function (CommandStarting $event): void {
            if (! DatabaseGuard::isDestructiveCommand($event->command)) {
                return;
            }

            if (DatabaseGuard::safeToDestroy()) {
                return;
            }

            // The prohibition above has already stopped this; what is added
            // here is a message naming the database and the way out, because
            // "prohibited from running in this environment" does not tell an
            // operator at an event what to do next.
            $event->output->writeln('<error>'.DatabaseGuard::refusal("php artisan {$event->command}").'</error>');

            throw new RuntimeException(DatabaseGuard::refusal("php artisan {$event->command}"));
        });
    }

    /**
     * Resolve the scoreboard driver from config, so tests and venues without
     * hardware swap it with an environment variable rather than a code change.
     */
    public function register(): void
    {
        /*
         | Where an account lands after signing in.
         |
         | A confined account goes where it may actually work — a referee to the
         | mats they score, a scoreboard account to its board — rather than to a
         | dashboard it is not allowed to open. Deliberately not to the intended
         | URL, which for these roles can only be somewhere they would be
         | refused.
         */
        $this->app->singleton(LoginResponse::class, fn (): LoginResponse => new class implements LoginResponse
        {
            public function toResponse($request): RedirectResponse
            {
                $user = $request->user();

                if ($user?->isReferee()) {
                    return redirect()->route('referee.mats');
                }

                return $user?->isScoreboardViewer()
                    ? redirect()->route('scoreboard.index')
                    : redirect()->intended(route('dashboard'));
            }
        });

        $this->app->singleton(ScoreboardDriver::class, fn () => match (config('scoreboard.driver')) {
            'fake' => new FakeScoreboardDriver,
            'null' => new NullScoreboardDriver,
            default => new HttpScoreboardDriver((int) config('scoreboard.timeout', 5)),
        });
    }

    /**
     * Anything that changes competition data — registration, weigh-ins, draws,
     * brackets, results — is restricted to admins and supervisors. Officials
     * and viewers get read-only screens.
     */
    protected function configureGates(): void
    {
        Gate::define('manage-competition', fn (User $user): bool => $user->canManageCompetition());

        // Accounts are the one thing only an admin touches.
        Gate::define('manage-users', fn (User $user): bool => $user->canManageUsers());

        /*
         | The scoreboard permissions, spelled out rather than inferred.
         |
         | Reading a board and scoring on one are different things, so an
         | operator holding scoreboard.view gains nothing towards changing a
         | score: that stays behind manage-competition, which no scoreboard
         | viewer will ever pass.
         |
         | The selection gates all reduce to the same question — is this
         | championship inside the account's scope — because a division and a
         | mat both belong to exactly one championship, and answering it in one
         | place is what stops the three from disagreeing.
         */
        /*
         | The competition screens themselves.
         |
         | Every working role reads them — an official needs the fight order in
         | front of them even though they cannot change it — but a scoreboard
         | account is not a working role, and this is what keeps it out of the
         | admin surface by typing a URL rather than by hiding a link.
         */
        Gate::define('access-admin', fn (User $user): bool => $user->is_active && ! $user->isConfinedToMat());

        /*
         | The published draw.
         |
         | An operator presents what an admin approved, and nothing else: this
         | permission opens the published table and the presentation that runs
         | off it, while generating, publishing and withdrawing stay behind
         | manage-competition. Reading a draw grants nothing towards drawing
         | one, which is the same separation the scoreboard permissions keep.
         */
        Gate::define('draw.view_published', fn (User $user): bool => $user->is_active && ! $user->isConfinedToMat());

        Gate::define('presentation.operate', fn (User $user): bool => $user->is_active && ! $user->isConfinedToMat());

        Gate::define('draw.publish', fn (User $user): bool => $user->canManageCompetition());

        /*
         | Running a small field as a knockout.
         |
         | The IKA rule draws two to five athletes as a round robin. This
         | system will run one as a bracket anyway, because a federation
         | sometimes has local reasons to — but it is a departure from the
         | rule, not a preference between two equal readings of it, and it is
         | narrower than every other draw decision on purpose.
         |
         | A supervisor may draw, publish, lock and delete. Only an
         | administrator may sign for a draw that does not follow the rule, and
         | only with a reason recorded against their name. Choosing the
         | compliant format needs nothing beyond manage-competition, so the
         | narrower permission gates the departure and nothing else.
         */
        Gate::define('draw.override_format', fn (User $user): bool => $user->isAdmin());

        /*
         | Signing a youth into an adults' competition.
         |
         | Section 25(2) of the IKA rules: "With the sanction of the Chief
         | Referee, youths (16-17 years) may also participate in adults'
         | competitions." The clause names one office, and this gate names the
         | same one — deliberately not `$user->isAdmin() || ...`, because an
         | approval that any senior account could have given does not record
         | who decided, which is the whole point of the rule naming somebody.
         |
         | Narrower than manage-competition on purpose: a supervisor runs the
         | entry list, and admitting a minor to an adults' competition is not
         | part of running an entry list.
         */
        Gate::define('athlete.sanction_age', fn (User $user): bool => $user->isChiefReferee());

        Gate::define('scoreboard.view', fn (User $user): bool => $user->canViewScoreboard());

        /*
         | Scoring a contest.
         |
         | Its own permission rather than a corner of manage-competition, which
         | also opens the entry list, the draw and the bracket. A referee holds
         | this and nothing else; an admin holds both and reaches the mat by the
         | same door. Every write on the mat screen is checked against this, so
         | a role added later inherits the separation instead of having to
         | remember it.
         |
         | Passed a mat, it asks the harder question: not "may this account
         | score" but "may this account score *here*". A referee holding the
         | role and not the assignment is refused, which is what stops mat
         | three being reached by editing a number in the address bar. Called
         | without one it answers the general question, for the places that ask
         | before a mat is in hand.
         */
        Gate::define(
            'score-bout',
            fn (User $user, ?Court $court = null): bool => $court === null
                ? $user->canScoreBouts()
                : $user->mayRefereeCourt($court)
        );

        /*
         | Opening a mat screen, as opposed to scoring on one.
         |
         | Everybody who works the competition may watch a mat — an official
         | following the session needs the screen in front of them, and took no
         | permission away from anybody by having it. The buttons on it are a
         | separate question, answered by score-bout above, so a viewer who
         | opens this reaches a board with nothing to press.
         */
        Gate::define(
            'mat.view',
            function (User $user, ?Court $court = null): bool {
                // Named a mat, this is the question that matters: not whether
                // the account works mats but whether it works *this* one.
                if ($court !== null && $user->isReferee()) {
                    return $user->mayRefereeCourt($court);
                }

                // Asked without one — the landing page, a menu — it is the
                // general question, and a referee with no assignment still
                // reaches the page that explains they have none.
                if ($user->isReferee()) {
                    return $user->is_active;
                }

                return $user->canScoreBouts()
                    || ($user->is_active && ! $user->isConfinedToMat());
            }
        );

        Gate::define(
            'scoreboard.select_event',
            fn (User $user, ?Championship $championship = null): bool => $user->mayViewChampionship($championship)
        );

        Gate::define(
            'scoreboard.select_division',
            fn (User $user, WeightCategory $category): bool => $user->mayViewChampionship($category->ageCategory?->championship)
        );

        /*
         | Reading one mat's board.
         |
         | A referee's scope is their assignment rather than the championship
         | the mat sits in, so the board follows the same rule the mat screen
         | does — otherwise an account barred from scoring on mat three could
         | still watch it, which is not what "assigned to mat one" means.
         */
        Gate::define(
            'scoreboard.select_court',
            fn (User $user, Court $court): bool => $user->isReferee()
                ? $user->mayRefereeCourt($court)
                : $user->mayViewChampionship($court->championship)
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
