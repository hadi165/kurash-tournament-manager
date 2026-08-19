<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closing a championship.
     *
     * The planning specification asks for an archive of completed competitions
     * and their result reports. An archive that is only a filter over a list is
     * not worth having — what makes it useful is that the competition stops
     * being editable, so a report pulled out of it two seasons later says the
     * same thing it said on the day.
     *
     * Reopening is allowed, because a genuine mistake found after the ceremony
     * has to be fixable. It is recorded rather than prevented.
     */
    public function up(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('ends_on');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();

            $table->index('archived_at');
        });

        Schema::create('championship_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);            // archived, reopened
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['championship_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_events');

        Schema::table('championships', function (Blueprint $table) {
            $table->dropForeign(['archived_by']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'archived_by']);
        });
    }
};
