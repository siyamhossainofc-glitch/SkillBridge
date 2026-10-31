<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioItem extends Model
{
    protected $fillable = ['provider_id', 'title', 'description', 'category_id', 'subcategory_id', 'project_url', 'image_url', 'sort_order', 'status', 'moderation_status', 'moderation_reason', 'moderated_by', 'moderated_at'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'moderated_at' => 'datetime'];
    }

    public function provider(): BelongsTo { return $this->belongsTo(User::class, 'provider_id'); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function subcategory(): BelongsTo { return $this->belongsTo(Subcategory::class); }
}
