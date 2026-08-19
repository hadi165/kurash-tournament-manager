<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->string('name')->nullable();
            $table->string('scoreboard_base_url')->nullable();
            $table->text('scoreboard_api_key')->nullable();   // encrypted cast on the model
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['championship_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courts');
    }
};
