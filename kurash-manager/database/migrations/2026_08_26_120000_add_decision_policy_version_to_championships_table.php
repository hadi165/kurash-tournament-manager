<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which edition of the winner rules a championship is fought under.
     *
     * The same shape as age_policy_version, and for the same reason: a
     * competition must keep being read under the rules it was run under. The
     * tie-break that decides a contest at time changed on 2026-08-26 — an
     * undocumented "who earned it" step was removed and the caution rule was
     * corrected from the latest caution to the first — and a bout completed
     * before that must not silently acquire a different winner because a later
     * edition shipped.
     *
     * Nullable, and it has to be: every championship already in the database
     * was created without one. Null resolves to
     * config('kurash.bout_decision.fallback_version'), never to the newest
     * edition. The distinction is the whole point — falling forward would
     * re-judge historical competitions, which is precisely what pinning exists
     * to prevent.
     *
     * No index. Nothing queries championships BY this column; it is read once
     * per bout through a relation the bout already loads.
     */
    public function up(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->unsignedSmallInteger('decision_policy_version')
                ->nullable()
                ->after('age_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->dropColumn('decision_policy_version');
        });
    }
};
