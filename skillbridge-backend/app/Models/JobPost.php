<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class JobPost extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'category',
        'category_id',
        'subcategory_id',
        'budget_min',
        'budget_max',
        'status',
        'location',
        'work_mode',
        'lat',
        'lng',
        'moderation_status',
        'moderation_reason',
        'moderated_by',
        'moderated_at',
        'deadline',
        'urgency',
        'preferred_experience',
    ];

    protected function casts(): array
    {
        return ['moderated_at' => 'datetime', 'deadline' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias of user() using the name the frontend expects: job.seeker.
     * The job owner is always a seeker (role enforced on POST /jobs).
     */
    public function seeker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function categoryRelation(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function bids()
    {
        return $this->hasMany(Bid::class);
    }

    public function contract()
    {
        return $this->hasOne(Contract::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function requiredSkills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'job_skill', 'job_post_id', 'skill_id');
    }
}
