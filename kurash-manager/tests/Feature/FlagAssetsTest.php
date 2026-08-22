<?php

use App\Models\AgeCategory;
use App\Models\Athlete;
use App\Models\User;
use App\Models\WeightCategory;
use App\Support\Noc;
use Illuminate\Support\Facades\Blade;

/**
 * These touch the filesystem through public_path(), so they need the
 * framework booted — the mapping itself is pure and tested in Unit.
 */
describe('the flag files themselves', function () {
    it('has a file on disk for every code it claims to know', function () {
        $missing = array_values(array_filter(
            Noc::codes(),
            fn (string $noc) => ! is_file(public_path('flags/'.Noc::iso($noc).'.svg'))
        ));

        // A mapping pointing at a file that is not there renders a broken
        // image, which is worse than showing no flag at all.
        expect($missing)->toBe([]);
    })->skip(
        fn () => ! is_dir(public_path('flags')),
        'flags are not copied yet — run: npm run flags'
    );

    it('resolves a real path for Dompdf', function () {
        expect(Noc::flagPath('UZB'))->toEndWith('public/flags/uz.svg')
            ->and(Noc::flagPath('ZZZ'))->toBeNull();
    })->skip(
        fn () => ! is_dir(public_path('flags')),
        'flags are not copied yet — run: npm run flags'
    );

    it('maps every code to a distinct country', function () {
        $isos = array_map(fn (string $noc) => Noc::iso($noc), Noc::codes());

        // Two NOCs sharing one flag would mean a typo in the table.
        expect(array_unique($isos))->toHaveCount(count($isos));
    });
});

describe('flags and branding on the screens', function () {
    it('shows a flag beside an athlete on the weigh-in screen', function () {
        $ageCategory = AgeCategory::factory()->create();
        $category = WeightCategory::factory()->create(['age_category_id' => $ageCategory->id]);

        // Lower case, exactly as the legacy import left it.
        Athlete::factory()->create([
            'championship_id' => $ageCategory->championship_id,
            'age_category_id' => $ageCategory->id,
            'weight_category_id' => $category->id,
            'noc_code' => 'uzb',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('weighin.index', ['championship' => $ageCategory->championship, 'competition' => $ageCategory->gender]))
            ->assertOk()
            ->assertSee('flags/uz.svg')     // resolved despite the case
            ->assertSee('UZB');             // and displayed normalised
    })->skip(fn () => ! is_dir(public_path('flags')), 'flags are not copied yet');

    it('names the federation rather than the framework in the app chrome', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(config('branding.organisation'));
    });

    /**
     * Until the official artwork is installed the system shows a plain
     * monogram. It must never show the starter kit's Laravel mark, which would
     * put another organisation's logo on a federation's screens.
     */
    it('falls back to a monogram when no logo file is installed', function () {
        config(['branding.logo' => 'images/does-not-exist.svg']);

        $html = Blade::render('<x-app-logo-icon />');

        expect($html)->toContain(config('branding.short_name'))
            ->and($html)->toContain('<svg');
    });
});
