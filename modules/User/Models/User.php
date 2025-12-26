<?php

namespace Modules\User\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Traits\HasRolesAndPermissions;
use OwenIt\Auditing\Contracts\Auditable;
 use Modules\Billing\Models\Subscription;

class User extends Authenticatable implements Auditable
{
    use HasApiTokens, Notifiable , HasRolesAndPermissions, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'stripe_customer_id',
        'firebase_uid',
        'profile_img',
        'trial_used',
        'trial_used_at',
        'bio',
        'lang',
        'time_zone',
        'email_notif',
        'push_notif'
    ];

    protected $hidden = [
        'password',
        'stripe_customer_id',
        'remember_token',
        'trial_used',
        'trial_used_at',
    ];

    protected $appends = ['profile_img_url','plan_id'];

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
        return $this->subscription()->where('status', 'active')->first()->type_id;
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }
}
