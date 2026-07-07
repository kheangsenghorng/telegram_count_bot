<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PackageTransaction extends Model
{
    use MassPrunable;

    protected $table = 'package_transactions';

    protected $primaryKey = 'packageTransactionsID';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'packageTransactionsID',
        'user_id',
        'subscription_id',
        'package_id',
        'amount',
        'currency',
        'payment_method',
        'external_transaction_id',
        'qr_code',
        'qr_image_url',
        'md5',
        'expires_at',

        'telegram_chat_id',
        'telegram_message_id',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (! $transaction->packageTransactionsID) {
                $transaction->packageTransactionsID = (string) Str::uuid();
            }
        });
    }

    /**
     * Delete pending transactions older than 7 days.
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays(7));
    }
        public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function subscription()
    {
        return $this->belongsTo(
            UserSubscription::class,
            'subscription_id',
            'userSubscriptionsID'
        );
    }

    public function package()
    {
        return $this->belongsTo(
            Package::class,
            'package_id',
            'packagesID'
        );
    }
}


