<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which buzzer a mat ends a contest on.
 *
 * Held per mat rather than per championship, because two mats running side by
 * side want to be told apart by ear: a hall where every mat sounds the same is
 * a hall where everybody looks up for the wrong one.
 *
 * Null means the configured default, which is what every mat had before there
 * was a choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->string('finish_sound', 191)->nullable()->after('scoreboard_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropColumn('finish_sound');
        });
    }
};
