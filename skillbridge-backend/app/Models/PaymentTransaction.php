<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'contract_id',
        'transaction_id',
        'milestone_id',
        'provider',
        'provider_payment_id',
        'provider_event_id',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'paid_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ContractMilestone::class);
    }

    public function escrowEntries(): HasMany
    {
        return $this->hasMany(EscrowEntry::class, 'payment_transaction_id');
    }
}
