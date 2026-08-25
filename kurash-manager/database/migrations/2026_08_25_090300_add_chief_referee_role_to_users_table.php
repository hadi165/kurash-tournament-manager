<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Chief Referee, as an account.
     *
     * Section 25(2) of the IKA rules gives one named official the power to let
     * a 16- or 17-year-old into an adults' competition. Nobody in this system
     * could exercise it, because nobody in this system was that official:
     * the roles ran admin, supervisor, official, viewer, scoreboard viewer and
     * referee, and the referee is confined to a mat by design.
     *
     * Deliberately not folded into the administrator. An administrator can do
     * everything, which is exactly why signing for a minor's entry must not be
     * one of the things they can do by virtue of being one — the rule names a
     * particular office, and an approval that anybody senior could have given
     * is not the approval the rule asks for. The account that signs is named
     * here for the same reason draw.override_format is narrower than
     * manage-competition.
     *
     * Reach: the competition screens, like a supervisor, because a sanction is
     * granted from the entry list. Not the mat: this is the office that
     * oversees refereeing, not a chair on it.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin', 'supervisor', 'chief_referee', 'official', 'viewer', 'scoreboard_viewer', 'referee')
             NOT NULL DEFAULT 'admin'"
        );
    }

    public function down(): void
    {
        // Anyone left on the departing role would fail the narrowed enum. They
        // become supervisors: the nearest surviving authority over a
        // competition, and the only one that still reaches the entry list.
        // Sanctions they already granted are untouched — those rows name the
        // account, not the role, and the log is not rewritten by a rollback.
        DB::table('users')->where('role', 'chief_referee')->update(['role' => 'supervisor']);

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin', 'supervisor', 'official', 'viewer', 'scoreboard_viewer', 'referee')
             NOT NULL DEFAULT 'admin'"
        );
    }
};
