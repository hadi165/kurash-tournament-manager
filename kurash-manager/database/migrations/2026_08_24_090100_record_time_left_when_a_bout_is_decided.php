<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the clock read when the contest was decided.
     *
     * `clock_seconds_left` is a live anchor: it is written while a contest runs
     * and it keeps moving until the mat stops it, so it says what the clock is
     * doing rather than what it said at the deciding moment. Nothing in this
     * system has ever recorded the latter, which is why the round-robin
     * tie-break on match time cannot be computed for anything fought so far.
     *
     * This is the durable reading, written once when a result is recorded and
     * left alone afterwards. It is nullable and stays null for every contest
     * already fought, for one decided through the scoreboard webhook with no
     * clock behind it, and for a walkover nobody stepped onto a mat for — all
     * of which are ordinary, and none of which may be guessed at.
     *
     * The tie-break that would read it is switched off by configuration until a
     * federation states which reading of the rule it wants; see
     * config/kurash.php. The column exists so that decision is a setting rather
     * than a schema change made in the middle of a championship.
     */
    public function up(): void
    {
        Schema::table('bouts', function (Blueprint $table) {
            $table->unsignedSmallInteger('decided_seconds_remaining')
                ->nullable()
                ->after('clock_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('bouts', function (Blueprint $table) {
            $table->dropColumn('decided_seconds_remaining');
        });
    }
};
