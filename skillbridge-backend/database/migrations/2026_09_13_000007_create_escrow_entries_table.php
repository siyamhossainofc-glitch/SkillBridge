<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_account_id')->constrained('escrow_accounts')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('contract_milestones')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('type', ['fund', 'hold', 'release', 'refund']);
            $table->enum('status', ['pending', 'held', 'released', 'refunded', 'failed'])->default('pending');
            $table->string('idempotency_key', 100)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['escrow_account_id', 'status']);
            $table->index(['contract_id', 'type', 'status']);
            $table->index(['payment_transaction_id', 'type']);
            $table->index(['milestone_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_entries');
    }
};
