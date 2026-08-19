<?php

namespace App\Support;

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\Championship;
use App\Models\Court;
use App\Models\WeightCategory;
use Illuminate\Support\Facades\Route;

/**
 * Which championship the page being viewed belongs to.
 *
 * The navigation the specification asks for — weigh-in form, entries by NOC,
 * fight order — only means anything inside one competition, but a bracket URL
 * carries a weight class and a mat URL carries a court. Rather than push a
 * championship id into every route, the sidebar asks the bound model what it
 * belongs to.
 *
 * Resolved from models the router has already bound, so this adds no query on
 * any page that was going to load its own subject anyway.
 */
final class CurrentChampionship
{
    public static function resolve(): ?Championship
    {
        // Route::current() is null outside an HTTP request — a console command
        // or a queued job rendering a view has no route to read.
        $route = Route::current();

        if ($route === null) {
            return null;
        }

        foreach ($route->parameters() as $parameter) {
            $championship = match (true) {
                $parameter instanceof Championship => $parameter,
                $parameter instanceof AgeCategory => $parameter->championship,
                $parameter instanceof WeightCategory => $parameter->ageCategory?->championship,
                $parameter instanceof Athlete => $parameter->championship,
                $parameter instanceof Court => $parameter->championship,
                default => null,
            };

            if ($championship !== null) {
                return $championship;
            }
        }

        return null;
    }

    /**
     * The category to open registration and the weigh-in form against.
     *
     * Both screens work on one age category. A championship with a single
     * category — most of them — should go straight there rather than making
     * someone pick from a list of one; anything else lands on the category
     * list, which is where the choice actually is.
     */
    public static function soleCategory(Championship $championship): ?AgeCategory
    {
        $categories = $championship->ageCategories()->limit(2)->get();

        return $categories->count() === 1 ? $categories->first() : null;
    }
}
