<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athletes', function (Blueprint $table) {
            $table->id();
            $table->string('ika_id', 16)->unique();
            $table->foreignId('championship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('age_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weight_category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('fullname');
            $table->enum('gender', ['M', 'F']);
            $table->string('noc_code', 8);
            $table->string('noc_name')->nullable();
            $table->string('national_id')->nullable();
            $table->string('club')->nullable();
            $table->string('photo_url')->nullable();

            $table->decimal('weighin_kg', 5, 2)->nullable();
            $table->enum('weighin_status', ['pending', 'pass', 'fail'])->default('pending');
            $table->timestamp('weighin_at')->nullable();

            $table->unsignedSmallInteger('draw_number')->nullable();
            $table->enum('draw_number_source', ['manual', 'import', 'random'])->nullable();

            $table->timestamps();

            $table->index(['championship_id', 'age_category_id', 'weight_category_id'], 'idx_athletes_scope');

            // Two athletes cannot share a draw number in the same category.
            // MySQL permits repeated NULLs, so undrawn athletes are unaffected.
            $table->unique(['weight_category_id', 'draw_number'], 'uniq_draw_per_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athletes');
    }
};
