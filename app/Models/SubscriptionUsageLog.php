<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionUsageLog extends Model
{
    protected $table = 'subscription_usage_logs';

    protected $primaryKey = 'usageLogID';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'usageLogID',
        'subscription_id',
        'user_id',
        'type',
        'action',
        'value',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            if (empty($log->usageLogID)) {
                $log->usageLogID = (string) Str::uuid();
            }
        });
    }

    public function subscription()
    {
        return $this->belongsTo(
            UserSubscription::class,
            'subscription_id',
            'userSubscriptionsID'
        );
    }

    public function user()
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'uuid'
        );
    }



    
}