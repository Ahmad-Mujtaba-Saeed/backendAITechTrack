<?php

namespace Modules\User\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Traits\HasRolesAndPermissions;
use OwenIt\Auditing\Contracts\Auditable;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Plan;

class User extends Authenticatable implements Auditable
{
    use HasApiTokens, Notifiable , HasRolesAndPermissions, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'stripe_customer_id',
        'firebase_uid',
        'profile_img',
        'trial_used',
        'trial_used_at',
        'bio',
        'lang',
        'time_zone',
        'email_notif',
        'push_notif',
        'is_active'
    ];

    protected $hidden = [
        'password',
        'stripe_customer_id',
        'remember_token',
        'trial_used_at',
    ];

    protected $appends = ['profile_img_url','plan_id','plan','plan_expire_date'];

    public function getProfileImgUrlAttribute()
    {
        if (empty($this->profile_img)) {
            return null;
        }

        return [
            'url' => url(Storage::url($this->profile_img)),
            'path' => $this->profile_img
        ];
    }

    public function getPlanIdAttribute()
    {
        $subscription = $this->subscription()
            ->where(function ($query) {
                $query->where('status', 'active')
                      ->orWhere('status', 'trialing');
            })
            ->first();

        return $subscription ? (int) $subscription->type_id : null;
    }

    public function getPlanAttribute()
    {
        $subscription = $this->subscription()
            ->whereIn('status', ['active', 'trialing'])
            ->first();

        if (!$subscription || !$subscription->type_id) {
            return null;
        }

        $plan = Plan::find($subscription->type_id);

        return $plan ?: null;
    }

    public function getPlanExpireDateAttribute(){
         $subscription = $this->subscription()->first();
         return $subscription ? $subscription->ends_at : null;
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }
}
