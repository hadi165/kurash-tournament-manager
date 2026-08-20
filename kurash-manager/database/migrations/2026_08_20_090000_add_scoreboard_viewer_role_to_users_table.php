<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A role for the people who only watch.
     *
     * The existing roles all belong to somebody working the competition. This
     * one is for a screen in a coaches' room or a federation office: it signs
     * in, it reads a scoreboard, and it can do nothing else. It is deliberately
     * not a variant of "viewer", which can still read every competition screen.
     *
     * Two columns come with it. `is_active` is what lets an admin close an
     * account without deleting the record it is referenced by, and the
     * scoreboard scope optionally pins a viewer to one championship — null
     * means every one, which is what most accounts will be.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin', 'supervisor', 'official', 'viewer', 'scoreboard_viewer')
             NOT NULL DEFAULT 'admin'"
        );

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');

            $table->foreignId('scoreboard_championship_id')
                ->nullable()
                ->after('is_active')
                ->constrained('championships')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scoreboard_championship_id');
            $table->dropColumn('is_active');
        });

        // Anyone left on the departing role would fail the narrowed enum.
        DB::table('users')->where('role', 'scoreboard_viewer')->update(['role' => 'viewer']);

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin', 'supervisor', 'official', 'viewer')
             NOT NULL DEFAULT 'admin'"
        );
    }
};
