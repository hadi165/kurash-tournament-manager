<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A division stops being a typed-in name and becomes what it always meant: one
 * of the championship's genders paired with one of its age groups.
 *
 * `name` stays, derived from the pair, because it is what every export, sheet
 * and screen already prints. What changes is that it is no longer the thing
 * being stored — it is the thing being spelled out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('age_categories', function (Blueprint $table) {
            $table->enum('gender', ['M', 'F', 'X'])->default('X')->after('championship_id');
            $table->string('age_group', 100)->nullable()->after('gender');
        });

        $this->backfill();

        Schema::table('age_categories', function (Blueprint $table) {
            $table->unique(['championship_id', 'gender', 'age_group'], 'age_categories_competition_unique');
        });
    }

    /**
     * The gender comes from the division's own weight classes, which have
     * carried it correctly all along — the name is only ever a description of
     * them. The age group comes from the name with the gender words removed.
     */
    private function backfill(): void
    {
        $divisions = DB::table('age_categories')->orderBy('id')->get();
        $taken = [];

        foreach ($divisions as $division) {
            $gender = DB::table('weight_categories')
                ->where('age_category_id', $division->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('gender');

            $gender = in_array($gender, ['M', 'F', 'X'], true) ? $gender : self::genderIn($division->name);

            $group = self::ageGroupIn($division->name);
            $key = $division->championship_id.'|'.$gender.'|'.$group;

            // Two divisions that reduce to the same pair would collide on the
            // unique key below. The one that got there first keeps the pair;
            // the other keeps its own name as its group, which is ugly but
            // true, and an organizer can tidy it on the categories screen.
            if (isset($taken[$key])) {
                $group = $division->name;
                $key = $division->championship_id.'|'.$gender.'|'.$group;
            }

            $taken[$key] = true;

            DB::table('age_categories')->where('id', $division->id)->update([
                'gender' => $gender,
                'age_group' => $group,
            ]);
        }
    }

    /** Falls back to reading the name when a division has no weight classes. */
    public static function genderIn(string $name): string
    {
        return match (true) {
            (bool) preg_match('/\b(women|woman|female|girls)\b/i', $name) => 'F',
            (bool) preg_match('/\b(men|man|male|boys)\b/i', $name) => 'M',
            default => 'X',
        };
    }

    public static function ageGroupIn(string $name): string
    {
        $stripped = preg_replace(
            '/\b(men|women|man|woman|male|female|open|mens|womens|men\'s|women\'s|boys|girls)\b/i',
            '',
            $name
        ) ?? $name;

        $stripped = trim(preg_replace('/\s+/', ' ', str_replace(['-', '–', '/'], ' ', $stripped)) ?? '');

        return $stripped === '' ? 'Senior' : $stripped;
    }

    public function down(): void
    {
        Schema::table('age_categories', function (Blueprint $table) {
            $table->dropUnique('age_categories_competition_unique');
            $table->dropColumn(['gender', 'age_group']);
        });
    }
};
