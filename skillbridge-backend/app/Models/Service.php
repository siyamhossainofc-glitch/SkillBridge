<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = ['provider_id', 'category_id', 'subcategory_id', 'title', 'slug', 'description', 'status', 'moderation_status', 'moderation_reason', 'moderated_by', 'moderated_at'];
    protected function casts(): array { return ['moderated_at' => 'datetime']; }

    public function provider(): BelongsTo { return $this->belongsTo(User::class, 'provider_id'); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function subcategory(): BelongsTo { return $this->belongsTo(Subcategory::class); }
    public function packages(): HasMany { return $this->hasMany(ServicePackage::class)->orderBy('sort_order')->orderBy('id'); }
}
