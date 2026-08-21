<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns a contest has to be recalculated from, rather than read off
     * a counter.
     *
     * bout_events already recorded that something happened and who did it.
     * What it could not answer was *how* a score came to exist — a chala the
     * referee awarded for a technique and a chala the software generated
     * because the opponent collected a tanbeh were the same row. The rules now
     * distinguish them: a dakki removes the automatic chala it supersedes and
     * leaves the earned one standing, and a contest level at time is decided
     * partly on which side's scores were earned rather than conceded.
     *
     * Three axes are kept apart deliberately, because collapsing them is what
     * made the old row ambiguous:
     *
     *   action        what kind of record this is  — scored, score_voided,
     *                 result_recorded, advanced, jazzo… (unchanged)
     *   entry_action  ADD | REMOVE | CORRECT — the specification's `action`,
     *                 kept under its own name so the existing column keeps its
     *                 existing meaning and no old row has to be rewritten
     *   origin        TECHNIQUE | MANUAL | AUTO_FROM_T | AUTO_FROM_D |
     *                 AUTO_FROM_MADICHAL — where the score came from. The
     *                 specification calls this `source`; `source` here already
     *                 means the channel that entered it (operator, scoreboard,
     *                 system), which is a different question and still worth
     *                 asking.
     *
     * parent_event_id ties an automatic award to the penalty that caused it, so
     * taking the penalty back takes its consequences back with it. Nothing has
     * to search for "the chala that probably came from that tanbeh".
     *
     * sequence_number is per bout and monotonic. created_at alone is not enough
     * to order two calls a referee made inside the same second, and the whole
     * point of the log is that replaying it in order reproduces the result.
     */
    public function up(): void
    {
        Schema::table('bout_events', function (Blueprint $table) {
            $table->enum('competitor_side', ['blue', 'green'])->nullable()->after('user_id');
            $table->string('event_type', 24)->nullable()->after('competitor_side');
            $table->enum('entry_action', ['ADD', 'REMOVE', 'CORRECT'])->nullable()->after('event_type');
            $table->string('origin', 24)->nullable()->after('source');
            $table->unsignedBigInteger('parent_event_id')->nullable()->after('origin');
            $table->unsignedInteger('sequence_number')->nullable()->after('parent_event_id');

            $table->foreign('parent_event_id')->references('id')->on('bout_events')->cascadeOnDelete();

            // The two reads the rules engine makes on every call: "this bout's
            // log in order", and "the children of this penalty".
            $table->index(['bout_id', 'sequence_number'], 'idx_bout_events_sequence');
            $table->index('parent_event_id', 'idx_bout_events_parent');
        });

        // Existing rows get a sequence so a replay of an old contest still has
        // a total order. Numbered by id within each bout, which is the order
        // they were written in.
        DB::statement('
            UPDATE bout_events e
            JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY bout_id ORDER BY id) AS seq
                FROM bout_events
            ) n ON n.id = e.id
            SET e.sequence_number = n.seq
            WHERE e.sequence_number IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('bout_events', function (Blueprint $table) {
            $table->dropForeign(['parent_event_id']);
            $table->dropIndex('idx_bout_events_sequence');
            $table->dropIndex('idx_bout_events_parent');
            $table->dropColumn([
                'competitor_side', 'event_type', 'entry_action',
                'origin', 'parent_event_id', 'sequence_number',
            ]);
        });
    }
};
