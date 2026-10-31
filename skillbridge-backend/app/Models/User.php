<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Notification;
use App\Models\Conversation;


class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;


    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'professional_title',
        'email',
        'password',
        'role',
        'moderation_status',
        'phone',
        'address',
        'lat',
        'lng',
        'nid_verified',
        'rating',
        'bio',
    ];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'nid_verified' => 'boolean',
            'is_verified' => 'boolean',
            'moderation_status' => 'string',
        ];
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'user_skill');
    }

    public function jobPosts()
    {
        return $this->hasMany(JobPost::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class, 'provider_id');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(ProviderAvailability::class, 'provider_id');
    }

    public function serviceAreas(): HasMany
    {
        return $this->hasMany(ProviderServiceArea::class, 'provider_id');
    }

    public function portfolioItems()
    {
        return $this->hasMany(PortfolioItem::class, 'provider_id');
    }

    public function savedJobs()
    {
        return $this->hasMany(SavedJob::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function bids()
    {
        return $this->hasMany(Bid::class, 'provider_id');
    }

    public function contractsAsSeeker()
    {
        return $this->hasMany(Contract::class, 'seeker_id');
    }

    public function contractsAsProvider()
    {
        return $this->hasMany(Contract::class, 'provider_id');
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function reviewsGiven()
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function verification(): HasOne
    {
        return $this->hasOne(ProviderVerification::class);
    }

    public function reviewsReceived()
    {
        return $this->hasMany(Review::class, 'reviewee_id');
    }

    public function conversationsAsA()
    {
        return $this->hasMany(Conversation::class, 'user_a_id');
    }

    public function conversationsAsB()
    {
        return $this->hasMany(Conversation::class, 'user_b_id');
    }

    public function disputesRaised(): HasMany
    {
        return $this->hasMany(Dispute::class, 'raised_by');
    }

    public function disputesAgainst(): HasMany
    {
        return $this->hasMany(Dispute::class, 'against_user_id');
    }

    public function disputesResolved(): HasMany
    {
        return $this->hasMany(Dispute::class, 'resolved_by');
    }

    public function adminAuditLogs(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'admin_user_id');
    }
}
