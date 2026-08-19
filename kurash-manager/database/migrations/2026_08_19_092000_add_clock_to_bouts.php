<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The contest clock, so more than one screen can see it.
     *
     * It began as browser state on the mat screen, which was fine while that
     * screen was the only thing showing it. A scoreboard on the wall changes
     * that: the clock has to be somewhere both can read.
     *
     * Stored as an anchor rather than a ticking value — how many seconds were
     * left, when that was true, and whether it is running. Every screen derives
     * the current reading from those three, so nothing has to write a row every
     * second and a display that reconnects is immediately correct.
     */
    public function up(): void
    {
        Schema::table('bouts', function (Blueprint $table) {
            $table->unsignedSmallInteger('clock_seconds_left')->nullable()->after('scoreboard_synced_at');
            $table->boolean('clock_running')->default(false)->after('clock_seconds_left');
            $table->timestamp('clock_updated_at')->nullable()->after('clock_running');
        });
    }

    public function down(): void
    {
        Schema::table('bouts', function (Blueprint $table) {
            $table->dropColumn(['clock_seconds_left', 'clock_running', 'clock_updated_at']);
        });
    }
};
