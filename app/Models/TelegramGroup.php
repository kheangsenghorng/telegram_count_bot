<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class TelegramGroup extends Model
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

        /*
        |--------------------------------------------------------------------------
        | Group connection/activity status
        |--------------------------------------------------------------------------
        */
        'connection_status',
        'activity_status',
        'last_activity_at',
        'last_heartbeat_at',

        /*
        |--------------------------------------------------------------------------
        | Existing status/timestamps
        |--------------------------------------------------------------------------
        */
        'last_payment_at',
        'bot_added_at',
        'connected_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'last_payment_at' => 'datetime',
            'bot_added_at' => 'datetime',
            'connected_at' => 'datetime',

            'last_activity_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TelegramGroup $group): void {
            if (empty($group->telegramGroupsID)) {
                $group->telegramGroupsID = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'uuid'
        );
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(
            UserSubscription::class,
            'subscription_id',
            'userSubscriptionsID'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status helpers
    |--------------------------------------------------------------------------
    */

    public function isOnline(): bool
    {
        return $this->connection_status === 'online';
    }

    public function isOffline(): bool
    {
        return $this->connection_status === 'offline';
    }

    public function isActive(): bool
    {
        return $this->activity_status === 'active';
    }

    public function markOnline(): void
    {
        $this->update([
            'connection_status' => 'online',
            'last_heartbeat_at' => now(),
        ]);
    }

    public function markOffline(): void
    {
        $this->update([
            'connection_status' => 'offline',
        ]);
    }

    public function markActivity(): void
    {
        $this->update([
            'connection_status' => 'online',
            'activity_status' => 'active',
            'last_activity_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }

    public function markInactive(): void
    {
        $this->update([
            'activity_status' => 'inactive',
        ]);
    }
}