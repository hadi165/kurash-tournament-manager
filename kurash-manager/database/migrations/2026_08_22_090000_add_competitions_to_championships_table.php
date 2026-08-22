<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A championship now declares which competitions it runs.
 *
 * Divisions used to be free text — somebody typed "Men Senior" into a box, and
 * nothing anywhere knew that the words meant a gender and an age group. Two
 * lists on the championship replace that: the genders it runs and the age
 * groups it runs, from which every division is a pair.
 *
 * The column is `age_groups` and not `age_categories` because `age_categories`
 * is already the table those pairs are rows in; naming both the same thing
 * would make every later query ambiguous to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->json('genders')->nullable()->after('title');
            $table->json('age_groups')->nullable()->after('genders');
        });

        $this->backfill();

        // Set only after the backfill: an existing championship gets the
        // configuration its divisions already implied, not the default.
        DB::table('championships')->whereNull('genders')->update(['genders' => json_encode(['M', 'F'])]);
        DB::table('championships')->whereNull('age_groups')->update(['age_groups' => json_encode(['Senior'])]);
    }

    /**
     * Reads each championship's existing divisions and writes back the
     * configuration they add up to, so nothing an organizer already entered has
     * to be entered again.
     */
    private function backfill(): void
    {
        $divisions = DB::table('age_categories')
            ->leftJoin('weight_categories', 'weight_categories.age_category_id', '=', 'age_categories.id')
            ->select('age_categories.championship_id', 'age_categories.name', 'weight_categories.gender')
            ->get();

        $configured = [];

        foreach ($divisions as $division) {
            $id = $division->championship_id;
            $configured[$id] ??= ['genders' => [], 'age_groups' => []];

            if (in_array($division->gender, ['M', 'F', 'X'], true)
                && ! in_array($division->gender, $configured[$id]['genders'], true)) {
                $configured[$id]['genders'][] = $division->gender;
            }

            $group = self::ageGroupIn($division->name);

            if (! in_array($group, $configured[$id]['age_groups'], true)) {
                $configured[$id]['age_groups'][] = $group;
            }
        }

        foreach ($configured as $id => $lists) {
            DB::table('championships')->where('id', $id)->update([
                'genders' => json_encode($lists['genders'] === [] ? ['M', 'F'] : $lists['genders']),
                'age_groups' => json_encode($lists['age_groups'] === [] ? ['Senior'] : $lists['age_groups']),
            ]);
        }
    }

    /**
     * "Men Senior" is an age group with a gender written in front of it, so the
     * gender words come out and what is left is the group. A name that is
     * nothing but a gender — "Men" — was never saying an age group at all, and
     * becomes Senior, which is what an unqualified division has always meant.
     */
    public static function ageGroupIn(string $name): string
    {
        $stripped = preg_replace(
            '/\b(men|women|man|woman|male|female|open|mens|womens|men\'s|women\'s)\b/i',
            '',
            $name
        ) ?? $name;

        $stripped = trim(preg_replace('/\s+/', ' ', str_replace(['-', '–', '/'], ' ', $stripped)) ?? '');

        return $stripped === '' ? 'Senior' : $stripped;
    }

    public function down(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->dropColumn(['genders', 'age_groups']);
        });
    }
};
