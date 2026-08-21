<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which mats a referee works.
     *
     * The role was scoped to a championship, which is the wrong unit: a
     * championship is every mat in the hall, and a referee is trusted with the
     * one in front of them. Holding the role is no longer enough to reach a mat
     * — the mat has to be assigned, and an account with none assigned reaches
     * nothing. That is deliberately the secure default rather than a
     * convenience: an account created and not yet given a mat should refuse,
     * not open every mat in the venue.
     *
     * Many-to-many because a small event runs two mats with one referee, and a
     * large one runs one mat with a referee per session.
     */
    public function up(): void
    {
        Schema::create('court_referee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // An assignment made twice is the same assignment. The constraint
            // is what makes that true rather than a check somebody remembered.
            $table->unique(['court_id', 'user_id']);

            // "Which mats does this account work" is the question asked on
            // every request a referee makes.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_referee');
    }
};
