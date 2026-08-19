<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces championsubs.corashweights / corashweights_text — two
     * slash-delimited strings that had to stay index-aligned by hand, and
     * silently mislabelled every athlete in a category when they drifted.
     */
    public function up(): void
    {
        Schema::create('weight_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('age_category_id')->constrained()->cascadeOnDelete();
            $table->string('label', 16);            // "-66", "+90"
            $table->decimal('min_kg', 5, 2)->nullable();
            $table->decimal('max_kg', 5, 2)->nullable();
            $table->enum('gender', ['M', 'F', 'X'])->default('X');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['age_category_id', 'label']);
        });

        // A category with both bounds must have them the right way round.
        DB::statement('
            ALTER TABLE weight_categories
            ADD CONSTRAINT chk_weight_bounds
            CHECK (min_kg IS NULL OR max_kg IS NULL OR min_kg < max_kg)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_categories');
    }
};
