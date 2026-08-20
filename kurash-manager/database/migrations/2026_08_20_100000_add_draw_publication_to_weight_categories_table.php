<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the draw was, at the moment it was drawn.
     *
     * The bracket itself has always been the bout rows, which reference their
     * athletes immutably — but nothing recorded the shape those rows were
     * generated from, or whether an admin had approved them for anybody else
     * to see. Both matter the moment somebody other than the person who ran
     * the draw is allowed to look at it.
     *
     * These live on the weight category because a weight category has exactly
     * one draw. A separate table would duplicate what the bouts already say
     * and give every existing query something new to learn.
     */
    public function up(): void
    {
        Schema::table('weight_categories', function (Blueprint $table) {
            // The figures the generator already computed but threw away, kept
            // so an operator sees the draw as it was published rather than as
            // today's registration list would recompute it.
            $table->timestamp('draw_generated_at')->nullable()->after('sort_order');
            $table->unsignedSmallInteger('draw_athlete_count')->nullable()->after('draw_generated_at');
            $table->unsignedSmallInteger('draw_bucket_size')->nullable()->after('draw_athlete_count');
            $table->unsignedSmallInteger('draw_bye_count')->nullable()->after('draw_bucket_size');

            // Bumped on every generation, so a page that was opened against an
            // older table can say so instead of mixing two versions of it.
            $table->unsignedInteger('draw_version')->default(0)->after('draw_bye_count');

            // Published is "an operator may see it". Locked is "not even an
            // admin regenerates it without unlocking first".
            $table->timestamp('draw_published_at')->nullable()->after('draw_version');
            $table->timestamp('draw_locked_at')->nullable()->after('draw_published_at');
        });
    }

    public function down(): void
    {
        Schema::table('weight_categories', function (Blueprint $table) {
            $table->dropColumn([
                'draw_generated_at',
                'draw_athlete_count',
                'draw_bucket_size',
                'draw_bye_count',
                'draw_version',
                'draw_published_at',
                'draw_locked_at',
            ]);
        });
    }
};
