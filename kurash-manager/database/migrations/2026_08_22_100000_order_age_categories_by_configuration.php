<?php

use App\Models\Championship;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put existing divisions in the order their championship declares.
 *
 * Divisions were created before there was an order to follow, so they all
 * carry sort_order 0 and come back in whatever order the database felt like.
 * That is now the order every screen opens on — the age group a registration
 * form defaults to, among others — so it has to mean something.
 */
return new class extends Migration
{
    public function up(): void
    {
        $championships = DB::table('championships')->select('id', 'genders', 'age_groups')->get();

        foreach ($championships as $championship) {
            $genders = self::listOf($championship->genders, ['M', 'F']);
            $groups = self::listOf($championship->age_groups, ['Senior']);

            // The lists were inferred from whatever order the old divisions
            // came back in, which was no order at all. Put the standard groups
            // in the federation's sequence — the first is what every screen
            // now opens on — and leave anything unrecognised after them.
            $standard = array_values(array_filter(
                Championship::AGE_GROUPS,
                fn (string $group) => in_array($group, $groups, true)
            ));

            $groups = [...$standard, ...array_values(array_filter(
                $groups,
                fn (string $group) => ! in_array($group, $standard, true)
            ))];

            DB::table('championships')->where('id', $championship->id)
                ->update(['age_groups' => json_encode($groups)]);

            $divisions = DB::table('age_categories')->where('championship_id', $championship->id)->get();

            foreach ($divisions as $division) {
                $competition = array_search($division->gender, $genders, true);
                $group = array_search($division->age_group, $groups, true);

                DB::table('age_categories')->where('id', $division->id)->update([
                    'sort_order' => ($competition === false ? 99 : $competition) * 100
                        + ($group === false ? 99 : $group),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function listOf(?string $json, array $fallback): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        if (! is_array($decoded) || $decoded === []) {
            return $fallback;
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * Not reversed. The order this replaced was no order at all, and writing
     * zeroes back would only make the next read arbitrary again.
     */
    public function down(): void {}
};
