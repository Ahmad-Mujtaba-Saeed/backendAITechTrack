<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;
use Modules\Billing\Models\Subscription;
use OwenIt\Auditing\Contracts\Auditable;

class SubscriptionHistory extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    
    protected $table = 'subscription_histories';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'name',
        'payment_id',
        'type',
        'type_id',
        'sub_id',
        'cus_id',
        'status',
        'trial_ends_at',
        'ends_at',
        'starts_at',
        'cancel_at_period_end',
        'action',
        'notes',
        'changed_by',
        'ip_address',
        'user_agent',
    ];


    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'type_id', 'id');
    }


    public function payments()
    {
        return $this->hasMany(Payment::class, 'subscription_id', 'sub_id');
    }

   
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public static function createFromSubscription(
        Subscription $subscription,
        string $action,
        ?string $notes = null,
        ?int $changedById = null
    ): self {
        return static::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'name' => $subscription->name,
            'type' => $subscription->type,
            'type_id' => $subscription->type_id,
            'sub_id' => $subscription->sub_id,
            'cus_id' => $subscription->cus_id,
            'status' => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at,
            'ends_at' => $subscription->ends_at,
            'starts_at' => $subscription->starts_at,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'action' => $action,
            'notes' => $notes,
            'changed_by' => $changedById,
            'ip_address' => request() ? request()->ip() : null,
            'user_agent' => request() ? request()->userAgent() : null,
        ]);
    }
}
