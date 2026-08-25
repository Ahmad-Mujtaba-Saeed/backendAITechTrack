<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Modules\User\Models\User;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Payment;
use OwenIt\Auditing\Contracts\Auditable;

class Subscription extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    
    protected $table = 'subscriptions';

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'type_id',
        'sub_id',
        'cus_id',
        'status',
        'trial_ends_at',
        'ends_at',
        'starts_at',
        'cancel_at_period_end'
    ];
    
    protected $attributes = [
        'cancel_at_period_end' => false
    ];

    
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'sub_id' => 'string',
        'cus_id' => 'string',
        'status' => 'string',
        'cancel_at_period_end' => 'boolean'
    ];

  
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

   
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

   
    public function cancelled(): bool
    {
        return !is_null($this->ends_at);
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

   
    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'type_id', 'id');
    }

    public function history()
    {
        return $this->hasMany(SubscriptionHistory::class)->latest();
    }

  
    protected static function booted()
    {
        // Create history record when subscription is created
        static::created(function ($subscription) {
            SubscriptionHistory::createFromSubscription(
                $subscription,
                'created',
                'Subscription was created',
                auth()->id()
            );
        });

        // Create history record when subscription is updated
        static::updated(function ($subscription) {
            $changes = $subscription->getChanges();
            
            // Don't log timestamp updates
            unset($changes['updated_at']);
            
            if (!empty($changes)) {
                $notes = 'Subscription updated. Changed: ' . implode(', ', array_keys($changes));
                
                SubscriptionHistory::createFromSubscription(
                    $subscription,
                    'updated',
                    $notes,
                    auth()->id()
                );
            }
        });

        // Create history record when subscription is deleted
        static::deleted(function ($subscription) {
            SubscriptionHistory::createFromSubscription(
                $subscription,
                'deleted',
                'Subscription was deleted',
                auth()->id()
            );
        });
    }
}
