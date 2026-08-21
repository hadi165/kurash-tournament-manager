<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contest length, set where the competition is defined.
     *
     * Cadets, juniors and seniors do not fight for the same time, and until now
     * the only way to say so was an environment variable keyed on gender —
     * which cannot express "this championship's cadets fight three minutes"
     * at all. It lives on the age category because that is the level the
     * distinction is actually drawn at; a null falls back to the configured
     * default for the weight class's gender, so an existing championship keeps
     * running exactly as it did.
     */
    public function up(): void
    {
        Schema::table('age_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('bout_seconds')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('age_categories', function (Blueprint $table) {
            $table->dropColumn('bout_seconds');
        });
    }
};
