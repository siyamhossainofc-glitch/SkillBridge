<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('against_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('contract_milestones')->nullOnDelete();
            $table->string('reason', 255);
            $table->text('description');
            $table->enum('status', ['open', 'under_review', 'resolved', 'rejected', 'cancelled'])->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            // Application-maintained for open/under_review rows; NULL for terminal states.
            // The unique constraint enforces one active dispute per contract/milestone scope.
            $table->string('active_scope_key', 160)->nullable()->unique();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
            $table->index(['raised_by', 'status']);
            $table->index(['against_user_id', 'status']);
            $table->index(['milestone_id', 'status']);
            $table->index(['resolved_by', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
