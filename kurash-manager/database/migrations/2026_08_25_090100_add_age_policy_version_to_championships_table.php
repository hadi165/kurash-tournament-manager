<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The edition of the age rules this championship is run under.
     *
     * Normally null, and normally right: an event is judged by whichever
     * version of the rules had come into force by the year it is held in, and
     * that is worked out from starts_on. This column is for the event that is
     * not normal — one held to the previous season's regulations, or a trial
     * of the next season's — where the organizer needs to say so rather than
     * have the software infer it from a date.
     *
     * A year rather than a name, because that is how config/kurash.php keys
     * the versions: they come into force in a year and stay in force until
     * something supersedes them.
     */
    public function up(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->unsignedSmallInteger('age_policy_version')->nullable()->after('age_groups');
        });
    }

    public function down(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->dropColumn('age_policy_version');
        });
    }
};
