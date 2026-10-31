<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    protected $fillable = [
        'contract_id',
        'raised_by',
        'against_user_id',
        'milestone_id',
        'reason',
        'description',
        'status',
        'resolution',
        'resolved_by',
        'resolved_at',
        'active_scope_key',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function againstUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_user_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ContractMilestone::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
