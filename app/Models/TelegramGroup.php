<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TelegramGroup extends Model
{
    protected $table = 'telegram_groups';

    protected $primaryKey = 'telegramGroupsID';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'telegramGroupsID',
        'user_id',
        'subscription_id',
        'group_id',
        'group_name',
        'group_type',
        'telegram_username',
        'last_payment_at',
        'bot_added_at',
        'connected_at',
        'status',
    ];

    protected $casts = [
        'last_payment_at' => 'datetime',
        'bot_added_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($group) {
            if (empty($group->telegramGroupsID)) {
                $group->telegramGroupsID = (string) Str::uuid();
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

    public function subscription()
    {
        return $this->belongsTo(
            UserSubscription::class,
            'subscription_id',
            'userSubscriptionsID'
        );
    }
}