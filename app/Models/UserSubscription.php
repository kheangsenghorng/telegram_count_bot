<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserSubscription extends Model
{
    protected $table = 'user_subscriptions';

    protected $primaryKey = 'userSubscriptionsID';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'userSubscriptionsID',
        'user_id',
        'package_id',
        'subscription_key',
        'override_payment_limit',
        'override_group_limit',
        'payment_used',
        'group_used',
        'starts_at',
        'ends_at',
        'status',
        'payment_method',
        'transaction_id',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($subscription) {
            if (!$subscription->userSubscriptionsID) {
                $subscription->userSubscriptionsID = (string) Str::uuid();
            }

            if (!$subscription->subscription_key) {
                $subscription->subscription_key = 'bot_' . strtolower(Str::random(40));
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'uuid'
        );
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id', 'packagesID');
    }
}