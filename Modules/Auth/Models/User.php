<?php

namespace Modules\Auth\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Traits\HasRolesAndPermissions;
use OwenIt\Auditing\Contracts\Auditable;
 
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
        'profile_img'
    ];

    protected $hidden = [
        'password',
        'stripe_customer_id',
        'remember_token',
    ];

    protected $appends = ['profile_img_url'];

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
}
