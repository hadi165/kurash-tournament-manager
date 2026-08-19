<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces championplaytablekurash.
     *
     * Two deliberate changes from the old table:
     *
     * 1. Athletes are referenced by id instead of having name, board, register
     *    and lottery number copied into eighteen columns. Correcting a
     *    misspelled name no longer leaves the old spelling on every sheet.
     *    frozen_snapshot keeps the defensibility that duplication was reaching
     *    for: it is written once, when the match completes.
     *
     * 2. next_bout_id / next_bout_slot point forward. The old table only had
     *    pre_playnumber_a/b pointing backward, and nothing ever traversed them
     *    in the result direction — which is why brackets stopped after round
     *    one. Advancement is now a single update.
     */
    public function up(): void
    {
        Schema::create('bouts', function (Blueprint $table) {
            $table->id();
            $table->string('play_code', 32)->unique();

            $table->foreignId('championship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('age_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weight_category_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('round');              // 1 = first round
            $table->unsignedSmallInteger('position_in_round');  // 0-indexed, top to bottom
            $table->unsignedSmallInteger('fight_number')->nullable();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('athlete_a_id')->nullable()->constrained('athletes')->nullOnDelete();
            $table->foreignId('athlete_b_id')->nullable()->constrained('athletes')->nullOnDelete();
            $table->unsignedSmallInteger('seed_a')->nullable();
            $table->unsignedSmallInteger('seed_b')->nullable();

            $table->unsignedBigInteger('next_bout_id')->nullable();
            $table->enum('next_bout_slot', ['a', 'b'])->nullable();

            $table->decimal('score_a', 5, 1)->nullable();
            $table->decimal('score_b', 5, 1)->nullable();
            $table->string('win_type', 32)->nullable();        // halal, yonbosh, chala, tanbeh, bye...
            $table->foreignId('winner_athlete_id')->nullable()->constrained('athletes')->nullOnDelete();

            $table->enum('status', ['pending', 'scheduled', 'on_court', 'completed'])->default('pending');
            $table->boolean('is_bye')->default(false);
            $table->json('frozen_snapshot')->nullable();
            $table->timestamp('scoreboard_synced_at')->nullable();

            $table->timestamps();

            $table->index(['weight_category_id', 'round'], 'idx_bouts_category_round');
            $table->index(['championship_id', 'fight_number'], 'idx_bouts_fight_order');
            $table->unique(['weight_category_id', 'round', 'position_in_round'], 'uniq_bracket_slot');
        });

        // Self-reference, added after the table exists.
        Schema::table('bouts', function (Blueprint $table) {
            $table->foreign('next_bout_id')->references('id')->on('bouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bouts');
    }
};
