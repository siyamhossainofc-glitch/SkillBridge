<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscrowAccount extends Model
{
    protected $fillable = ['contract_id', 'currency', 'balance', 'status'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(EscrowEntry::class);
    }
}
