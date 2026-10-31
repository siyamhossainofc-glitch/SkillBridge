<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePackage extends Model
{
    protected $fillable = ['service_id', 'name', 'description', 'price', 'delivery_days', 'revisions', 'sort_order'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'delivery_days' => 'integer', 'revisions' => 'integer', 'sort_order' => 'integer'];
    }

    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
}
