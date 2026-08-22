<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Halal was a misspelling. The call is KHALOL, and a federation's own term
     * being wrong on every result sheet is not a cosmetic problem.
     *
     * win_type is a plain string column, so this is a data change rather than a
     * schema one.
     *
     * Only the bout is rewritten. The event log is left exactly as it was
     * recorded — a row saying halal is what that operator's screen said at the
     * time, and an audit trail edited to agree with today's vocabulary is not
     * an audit trail. KurashScore reads the old spelling and maps it, so an
     * archived contest still tallies.
     */
    public function up(): void
    {
        DB::table('bouts')->where('win_type', 'halal')->update(['win_type' => 'khalol']);
    }

    public function down(): void
    {
        DB::table('bouts')->where('win_type', 'khalol')->update(['win_type' => 'halal']);
    }
};
