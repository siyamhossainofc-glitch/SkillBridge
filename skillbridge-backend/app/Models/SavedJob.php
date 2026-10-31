<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedJob extends Model
{
    protected $fillable = ['user_id', 'job_post_id'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function job(): BelongsTo { return $this->belongsTo(JobPost::class, 'job_post_id'); }
}
