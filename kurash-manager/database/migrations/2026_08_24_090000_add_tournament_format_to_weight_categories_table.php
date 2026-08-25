<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which tournament a class is run as, and who decided it.
     *
     * Two columns rather than one, because a preference and a fact are
     * different things. `draw_format_preference` is what an administrator has
     * chosen for a draw that has not been generated yet, and it may be changed
     * or abandoned. `draw_format` is what the draw that exists was actually
     * built as — written once, in the same transaction as the contests, and
     * read by every screen and export from then on.
     *
     * That is the same separation draw_bucket_size already keeps: an operator
     * presenting a table must see the table that was published, never what
     * today's entry list would recompute.
     *
     * The override columns exist because knockout in a field of two to five is
     * a departure from the IKA rule. A departure that nobody signed is one
     * nobody can answer for afterwards, so the reason, the administrator and
     * the moment are kept beside the draw they belong to.
     */
    public function up(): void
    {
        Schema::table('weight_categories', function (Blueprint $table) {
            $table->string('draw_format_preference', 16)->nullable()->after('draw_bye_count');
            $table->string('draw_format', 16)->nullable()->after('draw_format_preference');

            $table->text('draw_format_override_reason')->nullable()->after('draw_format');
            $table->foreignId('draw_format_override_by')->nullable()->after('draw_format_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('draw_format_override_at')->nullable()->after('draw_format_override_by');

            /*
             | The one-athlete category.
             |
             | It has a draw — somebody decided it — but it has no contests, so
             | the fact cannot live in the bouts table the way every other draw
             | does. Recorded here instead, with the administrator who placed
             | the athlete, because being unopposed is not the same as having
             | won and must never be inferred from a registration.
             */
            $table->foreignId('draw_placement_athlete_id')->nullable()->after('draw_format_override_at')
                ->constrained('athletes')->nullOnDelete();
            $table->foreignId('draw_placement_by')->nullable()->after('draw_placement_athlete_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('draw_placement_at')->nullable()->after('draw_placement_by');
        });

        $this->backfill();
    }

    /**
     * Stamp every draw that already exists as the knockout it is.
     *
     * Its own method so it can be run against real rows in a test rather than
     * only executing on an empty schema at migrate time — the whole risk this
     * guards against is an upgrade reinterpreting a competition that is
     * already half-fought, and that is not a risk a fresh database can show.
     *
     * @return int rows stamped
     */
    public function backfill(): int
    {
        /*
         | Everything already drawn is a knockout bracket, because that is the
         | only thing this system has ever generated.
         |
         | Stamped explicitly rather than left null and inferred later: an
         | upgrade must not be able to reinterpret a competition that is
         | half-fought, and a null that some future resolver reads as "decide
         | from the athlete count" would do exactly that to every small class.
         |
         | The preference is deliberately left null. No administrator expressed
         | one, and inventing a retrospective choice would put words in their
         | mouth on an audit trail.
         */
        return DB::table('weight_categories')
            ->whereNull('draw_format')
            ->where(function ($query) {
                $query->whereNotNull('draw_generated_at')
                    ->orWhereExists(function ($exists) {
                        $exists->selectRaw(1)
                            ->from('bouts')
                            ->whereColumn('bouts.weight_category_id', 'weight_categories.id');
                    });
            })
            ->update(['draw_format' => 'knockout']);
    }

    public function down(): void
    {
        Schema::table('weight_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draw_format_override_by');
            $table->dropConstrainedForeignId('draw_placement_athlete_id');
            $table->dropConstrainedForeignId('draw_placement_by');

            $table->dropColumn([
                'draw_format_preference',
                'draw_format',
                'draw_format_override_reason',
                'draw_format_override_at',
                'draw_placement_at',
            ]);
        });
    }
};
