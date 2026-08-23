<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many accreditation numbers a championship has issued.
 *
 * Counted rather than derived from the athletes still entered. Taking the
 * highest number in use hands the next arrival the number of somebody who
 * withdrew — and if that person's card was printed before they pulled out,
 * two people have held it at the same door.
 *
 * Seeded from the highest already issued, so nothing in flight is disturbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->unsignedInteger('athletes_numbered')->default(0)->after('age_groups');
        });

        $highest = DB::table('athletes')
            ->where('ika_id', 'like', 'IKA%')
            ->selectRaw('championship_id, MAX(CAST(SUBSTRING(ika_id, 4) AS UNSIGNED)) AS n')
            ->groupBy('championship_id')
            ->pluck('n', 'championship_id');

        foreach ($highest as $championship => $number) {
            DB::table('championships')->where('id', $championship)
                ->update(['athletes_numbered' => (int) $number]);
        }
    }

    public function down(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->dropColumn('athletes_numbered');
        });
    }
};
