<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jazzo: half the contest gone with nothing scored by either athlete, and
     * the referee stops it.
     *
     * Two timestamps rather than one flag, because the board has to distinguish
     * "stopped for jazzo now" from "was stopped for jazzo earlier and resumed"
     * — the yellow box belongs on screen only for the first, and the second is
     * what stops a contest being halted twice at the same halfway mark.
     */
    public function up(): void
    {
        Schema::table('bouts', function (Blueprint $table) {
            $table->timestamp('jazzo_called_at')->nullable()->after('clock_updated_at');
            $table->timestamp('jazzo_resumed_at')->nullable()->after('jazzo_called_at');
        });
    }

    public function down(): void
    {
        Schema::table('bouts', function (Blueprint $table) {
            $table->dropColumn(['jazzo_called_at', 'jazzo_resumed_at']);
        });
    }
};
