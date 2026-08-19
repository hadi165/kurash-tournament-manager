<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit trail a medal decision needs: who changed a result, when, and
     * what it was before. The old system had no equivalent — a disputed result
     * could not be reconstructed.
     */
    public function up(): void
    {
        Schema::create('bout_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bout_id')->constrained('bouts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);          // result_recorded, result_corrected, advanced, generated
            $table->string('source', 20)->default('system'); // scoreboard, operator, system
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bout_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bout_events');
    }
};
