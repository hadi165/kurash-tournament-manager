<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('age_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained()->cascadeOnDelete();
            $table->string('name');                 // e.g. "Men Senior"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['championship_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_categories');
    }
};
