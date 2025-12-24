<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Plan extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'price',
        'subdesc',
        'currency',
        'interval',
        'interval_count',
        'stripe_product_id',
        'stripe_price_id',
        'features',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
