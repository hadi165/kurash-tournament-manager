<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a mat sounds the end of a contest at all.
 *
 * Held beside the choice of which buzzer rather than folded into it, because
 * they are different questions: a mat turned off for a warm-up hall or a
 * training session still has a sound it would use, and turning it back on
 * should not mean choosing again.
 *
 * On by default, which is what every mat did before there was a switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->boolean('finish_sound_enabled')->default(true)->after('finish_sound');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropColumn('finish_sound_enabled');
        });
    }
};
