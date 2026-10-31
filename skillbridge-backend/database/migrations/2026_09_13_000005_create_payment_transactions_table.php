<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('contract_milestones')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->enum('status', ['initiated', 'pending', 'succeeded', 'failed', 'cancelled'])->default('initiated');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['contract_id', 'status']);
            $table->index(['milestone_id', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
