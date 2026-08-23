<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IKA numbers start at 001 in every championship.
 *
 * They were derived from the primary key and padded to six digits, so the
 * first athlete of a second event carried on from where the first left off —
 * IKA000171 rather than IKA001. An accreditation number is read off a card by
 * a person at a door, and three digits is what it is for.
 *
 * Which means they can only be unique within their championship, so the
 * constraint moves with them. A card belongs to one event, and so does the
 * number on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropUnique('athletes_ika_id_unique');
        });

        $this->renumber();

        Schema::table('athletes', function (Blueprint $table) {
            $table->unique(['championship_id', 'ika_id'], 'athletes_championship_ika_unique');
        });
    }

    /**
     * In registration order, which is the order they were numbered in before —
     * so an athlete's position in their delegation's list does not move, only
     * the number written on it.
     */
    private function renumber(): void
    {
        $championships = DB::table('athletes')->distinct()->pluck('championship_id');

        foreach ($championships as $championship) {
            $number = 0;

            $athletes = DB::table('athletes')
                ->where('championship_id', $championship)
                ->orderBy('id')
                ->pluck('id');

            foreach ($athletes as $id) {
                DB::table('athletes')->where('id', $id)->update([
                    'ika_id' => 'IKA'.str_pad((string) ++$number, 3, '0', STR_PAD_LEFT),
                ]);
            }
        }
    }

    /**
     * Not reversed. Going back would have to invent six-digit numbers that no
     * longer mean anything, and a card printed since is printed.
     */
    public function down(): void {}
};
