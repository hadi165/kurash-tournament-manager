<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Chief Referee sanction under Section 25(2), and every withdrawal
     * of one.
     *
     * ── Why this is a log and not two columns on the athlete ──────────────
     *
     * A sanction is a decision a named official took on a date, for a reason,
     * under a stated version of the rules. Kept as columns on the athlete it
     * would have exactly one value — the current one — and granting, revoking
     * and re-granting would overwrite each other until the row said only what
     * happened last. The question an inquiry asks afterwards is not "is this
     * athlete sanctioned" but "who allowed a sixteen-year-old into the seniors
     * on the morning of the finals, and what did they say at the time".
     *
     * So rows are appended and never changed. Whether a sanction is in force
     * right now is derived by reading the newest row for the pair — see
     * App\Services\AgeSanctions — which is the same shape bout_events uses for
     * the score of a contest, and for the same reason.
     *
     * ── What is copied onto the row on purpose ────────────────────────────
     *
     * policy_version, competition_year, birth_year and competition_age are all
     * derivable when the row is written and none of them are derivable
     * afterwards. The rules get a new edition, a date of birth gets corrected,
     * the championship's dates get edited: any of those would silently rewrite
     * history if this table asked for them again at reading time. What is
     * recorded is what the official was told when they signed.
     */
    public function up(): void
    {
        Schema::create('athlete_age_sanctions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();

            /*
             | The division the sanction is for.
             |
             | A sanction is not a property of the athlete but of one entry:
             | signing a youth into the men's seniors says nothing about the
             | veterans, and moving them to another division is a new decision.
             | Kept even if the division is later deleted, because the record of
             | the decision outlives the division it was about.
             */
            $table->foreignId('age_category_id')->nullable()->constrained('age_categories')->nullOnDelete();
            $table->foreignId('championship_id')->nullable()->constrained('championships')->nullOnDelete();

            // granted | revoked. A string rather than an enum: this table is
            // append-only and a third kind of entry must not need an ALTER.
            $table->string('action', 16);

            $table->text('reason');

            // Nullable so that closing an account does not take the history
            // with it — the same choice bout_events makes for user_id.
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();

            // What the signer was told, frozen. See the note above.
            $table->unsignedSmallInteger('policy_version')->nullable();
            $table->unsignedSmallInteger('competition_year')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('competition_age')->nullable();
            $table->string('age_group', 100)->nullable();

            // created_at only. The row is never updated, so there is nothing
            // for an updated_at to describe.
            $table->timestamp('created_at')->useCurrent();

            // The lookup every read does: the newest row for one athlete in
            // one division.
            $table->index(['athlete_id', 'age_category_id', 'id'], 'idx_age_sanctions_current');
            $table->index(['championship_id', 'created_at'], 'idx_age_sanctions_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_age_sanctions');
    }
};
