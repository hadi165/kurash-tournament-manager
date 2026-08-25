<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an athlete was born, and who checked.
     *
     * Nullable, and it has to be: every athlete already in the database was
     * registered without one, and a competition that is half fought must not
     * become unreadable because a column was added to it. What the nullability
     * does not mean is that the date is optional going forward — the
     * registration form asks for it, and an athlete without one cannot pass a
     * credentials check. See App\Services\AgeEligibilityPolicy.
     *
     * The date itself is deliberately the only thing stored. Age is not a
     * column here: it is a function of the competition year, so an athlete
     * carrying their own age would be wrong from January and wrong again every
     * season after that.
     *
     * The verification pair records that somebody looked at a passport rather
     * than that somebody typed a number. It is separate from the date because
     * the two are different facts — a date can be entered by a delegation from
     * a spreadsheet, and checking it against a document is an act by a named
     * person at a desk.
     */
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('national_id');

            $table->timestamp('date_of_birth_verified_at')->nullable()->after('date_of_birth');
            $table->foreignId('date_of_birth_verified_by')->nullable()->after('date_of_birth_verified_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('athletes', function (Blueprint $table) {
            /*
             | One index, for the question the screens actually ask across a
             | whole event: who in this championship still has no date of birth.
             | That is the accreditation desk's worklist, and it is the only
             | date-of-birth query that runs over more than one division.
             |
             | Deliberately not a matching (age_category_id, date_of_birth)
             | index. MariaDB will adopt it as the index supporting the
             | age_category_id foreign key — there is no other index with that
             | column leading — and it then cannot be dropped again, which
             | makes this migration irreversible for the sake of ordering a few
             | dozen athletes by age. The championship pairing has no such
             | effect: idx_athletes_scope already leads on championship_id and
             | goes on supporting that key.
             */
            $table->index(['championship_id', 'date_of_birth'], 'idx_athletes_championship_dob');
        });
    }

    public function down(): void
    {
        // The foreign key first, then the index, then the columns. An index is
        // not droppable while a constraint is leaning on it, and a column is
        // not droppable while an index still names it, so the order is the
        // reverse of the order they were made in.
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('date_of_birth_verified_by');
        });

        Schema::table('athletes', function (Blueprint $table) {
            $table->dropIndex('idx_athletes_championship_dob');
        });

        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'date_of_birth_verified_at']);
        });
    }
};
