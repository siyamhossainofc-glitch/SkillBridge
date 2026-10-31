<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // Always store participants with lower id first to enforce uniqueness
            $table->unsignedBigInteger('user_a_id');
            $table->unsignedBigInteger('user_b_id');
            $table->timestamps();

            $table->foreign('user_a_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('user_b_id')->references('id')->on('users')->cascadeOnDelete();

            // Prevent duplicate conversations between the same two users
            $table->unique(['user_a_id', 'user_b_id']);

            $table->index('user_a_id');
            $table->index('user_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
