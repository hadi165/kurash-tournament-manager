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
use App\Services\Scoreboard\FakeScoreboardDriver;
use App\Services\Scoreboard\HttpScoreboardDriver;
use App\Services\Scoreboard\NullScoreboardDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();

        // Every write to a bout invalidates the display screens for its
        // championship, whatever caused it.
        Bout::observe(BoutObserver::class);

        // An archived championship is read-only. Enforced here rather than in
        // each screen, so a mutation path added later inherits the guard
        // instead of having to remember it.
        foreach ([AgeCategory::class, Athlete::class, Bout::class, Court::class, WeightCategory::class] as $model) {
            $model::observe(ArchivedChampionshipGuard::class);
        }
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
         | A scoreboard account goes to its board rather than to a dashboard it
         | is not allowed to open — and deliberately not to the intended URL,
         | which for this role can only be somewhere it would be refused.
         */
        $this->app->singleton(LoginResponse::class, fn (): LoginResponse => new class implements LoginResponse
        {
            public function toResponse($request): RedirectResponse
            {
                $user = $request->user();

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
        Gate::define('access-admin', fn (User $user): bool => $user->is_active && ! $user->isScoreboardViewer());

        Gate::define('scoreboard.view', fn (User $user): bool => $user->canViewScoreboard());

        Gate::define(
            'scoreboard.select_event',
            fn (User $user, ?Championship $championship = null): bool => $user->mayViewChampionship($championship)
        );

        Gate::define(
            'scoreboard.select_division',
            fn (User $user, WeightCategory $category): bool => $user->mayViewChampionship($category->ageCategory?->championship)
        );

        Gate::define(
            'scoreboard.select_court',
            fn (User $user, Court $court): bool => $user->mayViewChampionship($court->championship)
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
