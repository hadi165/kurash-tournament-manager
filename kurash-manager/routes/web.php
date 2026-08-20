<?php

use App\Http\Controllers\DisplayController;
use App\Http\Controllers\ExportController;
use App\Http\Middleware\AllowPublicDisplay;
use App\Livewire\Competition\Archive;
use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Categories;
use App\Livewire\Competition\Championships;
use App\Livewire\Competition\Courts;
use App\Livewire\Competition\Dashboard;
use App\Livewire\Competition\DrawCeremony;
use App\Livewire\Competition\Entries;
use App\Livewire\Competition\FightOrder;
use App\Livewire\Competition\MatControl;
use App\Livewire\Competition\Medals;
use App\Livewire\Competition\Registration;
use App\Livewire\Competition\Scoreboard;
use App\Livewire\Competition\WeighIn;
use Illuminate\Support\Facades\Route;

/*
 | The front door goes straight to the competition.
 |
 | Nobody arriving at a federation's competition system wants a framework
 | splash screen. Signed in, this is the dashboard; signed out, it is the login
 | page — which is what the specification asks for anyway: a login before any
 | competition data.
 */
Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

/*
 | Venue display screens.
 |
 | Deliberately outside the Livewire application. These have as many viewers as
 | the hall holds, so they are rendered once per change and served from cache to
 | everyone else — a bracket on wire:poll in front of 200 spectators is what
 | actually falls over at an event.
 |
 | Anonymous access is off unless DISPLAY_PUBLIC is set; see config/display.php.
 */
Route::middleware(AllowPublicDisplay::class)->prefix('display')->name('display.')->group(function () {
    Route::get('championships/{championship}', [DisplayController::class, 'mats'])->name('mats');
    Route::get('championships/{championship}/fight-order', [DisplayController::class, 'fightOrder'])->name('fight-order');
    Route::get('championships/{championship}/medals', [DisplayController::class, 'medals'])->name('medals');
    Route::get('weight-classes/{weightCategory}/bracket', [DisplayController::class, 'bracket'])->name('bracket');

    /*
     | The live mat scoreboard, opened on its own screen.
     |
     | Under the same public-display gate as the screens above, because it hangs
     | on a wall in the same hall — but it is a Livewire component rather than a
     | cached render. One viewer that must be right within a second is the
     | opposite trade from a bracket two thousand people are reading.
     */
    Route::get('mats/{court}/scoreboard', Scoreboard::class)->name('scoreboard');

    /*
     | The draw ceremony board, shown while positions are being pulled.
     |
     | Same gate and same trade as the scoreboard: one projector, updating
     | itself, in front of a hall that is watching a draw being made. Read-only
     | — the draw itself is made and committed on the admin screen.
     */
    Route::get('weight-classes/{weightCategory}/draw-ceremony', DrawCeremony::class)->name('draw-ceremony');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    Route::get('archive', Archive::class)->name('archive.index');

    // Competition workflow, in the order an event actually runs.
    Route::get('championships', Championships::class)->name('championships.index');
    Route::get('championships/{championship}', Categories::class)->name('championships.show');
    Route::get('championships/{championship}/entries', Entries::class)->name('entries.index');
    Route::get('championships/{championship}/medals', Medals::class)->name('medals.index');
    Route::get('championships/{championship}/mats', Courts::class)->name('courts.index');
    Route::get('mats/{court}/live', MatControl::class)->name('mats.live');
    Route::get('championships/{championship}/fight-order', FightOrder::class)->name('fight-order.index');

    Route::get('categories/{ageCategory}/athletes', Registration::class)->name('athletes.index');
    Route::get('categories/{ageCategory}/weigh-in', WeighIn::class)->name('weighin.index');

    Route::get('weight-classes/{weightCategory}/bracket', Bracket::class)->name('bracket.show');

    // Printable and downloadable paperwork. Every table the specification asks
    // for, in both formats, rendered from the database on request so a
    // re-download is never out of date.
    // The format constraint has to be declared before group(), not chained
    // after it — the routes are registered by the time group() returns.
    // Laid-out documents rather than tables, so they are PDF only and sit
    // outside the format-constrained group below.
    Route::prefix('exports')->name('exports.')->group(function () {
        Route::get('championships/{championship}/certificates.pdf', [ExportController::class, 'certificates'])->name('certificates');
        Route::get('weight-classes/{weightCategory}/certificates.pdf', [ExportController::class, 'categoryCertificates'])->name('certificates.category');

        Route::get('championships/{championship}/accreditation.pdf', [ExportController::class, 'accreditation'])->name('accreditation');
        Route::get('categories/{ageCategory}/accreditation.pdf', [ExportController::class, 'categoryAccreditation'])->name('accreditation.category');
        Route::get('athletes/{athlete}/accreditation.pdf', [ExportController::class, 'athleteAccreditation'])->name('accreditation.athlete');
    });

    Route::prefix('exports')->name('exports.')->where(['format' => 'pdf|csv'])->group(function () {
        Route::get('weight-classes/{weightCategory}/weigh-in.{format}', [ExportController::class, 'confirmedWeighIn'])->name('weigh-in');
        Route::get('weight-classes/{weightCategory}/draw.{format}', [ExportController::class, 'drawSheet'])->name('draw');

        Route::get('championships/{championship}/fight-order.{format}', [ExportController::class, 'fightOrder'])->name('fight-order');
        Route::get('championships/{championship}/entries-by-noc.{format}', [ExportController::class, 'entriesByNoc'])->name('entries-noc');
        Route::get('championships/{championship}/entries-by-weight.{format}', [ExportController::class, 'entriesByWeight'])->name('entries-weight');
        Route::get('championships/{championship}/results.{format}', [ExportController::class, 'results'])->name('results');
        Route::get('championships/{championship}/medal-standing.{format}', [ExportController::class, 'medalStanding'])->name('medals');
    });
});

require __DIR__.'/settings.php';
