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
       // ── ABA PayWay (added by migration) ──────────────────────────
       'gateway',                  // 'bakong' | 'aba_payway'
       'merchant_ref_no',          // our PAY-... ref sent to ABA
       'checkout_url',             // payment link URL — stored at creation
       'aba_tran_id',              // ABA tran_id from callback
       'create_log_id',            // PayWay create-link log ID
       'gateway_status',           // OPEN / APPROVED / ...
       'create_response',          // full create-link response (JSON)
       
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'create_response' => 'array',
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