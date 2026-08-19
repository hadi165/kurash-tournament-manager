<?php

namespace App\Providers;

use App\Contracts\ScoreboardDriver;
use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Court;
use App\Models\User;
use App\Models\WeightCategory;
use App\Observers\ArchivedChampionshipGuard;
use App\Observers\BoutObserver;
use App\Services\Scoreboard\FakeScoreboardDriver;
use App\Services\Scoreboard\HttpScoreboardDriver;
use App\Services\Scoreboard\NullScoreboardDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
