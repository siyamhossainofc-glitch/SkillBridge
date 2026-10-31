<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractMilestone extends Model
{
    protected $fillable = [
        'contract_id',
        'title',
        'description',
        'amount',
        'due_date',
        'status',
        'submitted_at',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'milestone_id');
    }

    public function escrowEntries(): HasMany
    {
        return $this->hasMany(EscrowEntry::class, 'milestone_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'milestone_id');
    }
}
