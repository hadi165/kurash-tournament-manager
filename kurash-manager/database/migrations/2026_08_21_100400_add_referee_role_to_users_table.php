<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The referee, as an account.
     *
     * Scoring a contest was previously inside manage-competition, which also
     * carries the draw, the entry list and the bracket. A referee is trusted
     * with one mat and nothing else, and an account that can score a contest
     * should not be able to regenerate the table it sits in. This role reaches
     * the mat screen and the score board, and no other screen in the system.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin', 'supervisor', 'official', 'viewer', 'scoreboard_viewer', 'referee')
             NOT NULL DEFAULT 'admin'"
        );
    }

    public function down(): void
    {
        // Anyone left on the departing role would fail the narrowed enum. They
        // become officials: read-only across the competition, which is the
        // nearest thing that still exists.
        DB::table('users')->where('role', 'referee')->update(['role' => 'official']);

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin', 'supervisor', 'official', 'viewer', 'scoreboard_viewer')
             NOT NULL DEFAULT 'admin'"
        );
    }
};
