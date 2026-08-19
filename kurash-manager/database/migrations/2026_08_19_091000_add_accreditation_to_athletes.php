<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the entrance card has to print beyond the athlete's own details.
     *
     * The specification asks the accreditation card to carry a position and the
     * access areas the holder is cleared for. Position is a string rather than
     * an enum because a delegation brings coaches, referees, medical staff and
     * officials whose titles are not this system's to fix.
     */
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->string('position_title')->nullable()->after('club');
            $table->json('accreditation_areas')->nullable()->after('position_title');
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn(['position_title', 'accreditation_areas']);
        });
    }
};
