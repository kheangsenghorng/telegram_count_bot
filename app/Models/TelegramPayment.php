<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TelegramPayment extends Model
{
    use MassPrunable;

    protected $table = 'telegram_payments';

    protected $primaryKey = 'telegram_paymentID';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'telegram_paymentID',
        'user_id',
        'telegram_group_id',
        'subscription_id',
        'currency',
        'amount',
        'payer_name',
        'payer_account',
        'merchant_name',
        'payment_method',
        'bank_code',
        'trx_id',
        'apv',
        'payment_date',
        'report_date',
        'report_month',
        'report_year',
        'raw_message',
        'parsed_successfully',
        'is_duplicate',
        'status',
    ];

    protected $casts = [
        'amount'              => 'decimal:2',
        'payment_date'        => 'datetime',
        'report_date'         => 'date',
        'report_month'        => 'integer',
        'report_year'         => 'integer',
        'parsed_successfully' => 'boolean',
        'is_duplicate'        => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $payment) {
            if (! $payment->telegram_paymentID) {
                $payment->telegram_paymentID = (string) Str::uuid();
            }
        });

        // ── Auto-consume subscription quota ──────────────────────────────
        // When a real (parsed, non-duplicate) payment is captured and linked
        // to a subscription, increment payment_used automatically.
        static::created(function (self $payment) {
            if ($payment->countsTowardQuota()) {
                $payment->subscription?->consumePayment();
            }
        });

        // ── Refund quota when a counted payment is deleted ───────────────
        // e.g. manually deleting orphan/incorrect rows should give the
        // quota back so the user is not charged for it.
        static::deleted(function (self $payment) {
            if (! $payment->countsTowardQuota()) {
                return;
            }

            $subscription = $payment->subscription;

            if (
                $subscription
                && ! $subscription->isUnlimitedPayments()
                && $subscription->payment_used > 0
            ) {
                $subscription->decrement('payment_used');
            }
        });
    }

    /**
     * Whether this payment should count against the subscription quota.
     * Parse-failed rows (trx_id NULL) and duplicates never consume quota.
     */
    public function countsTowardQuota(): bool
    {
        return $this->subscription_id !== null
            && $this->parsed_successfully
            && ! $this->is_duplicate;
    }

    /**
     * Auto-delete orphan payments:
     * rows with no user, no group, and no subscription.
     */
    public function prunable(): Builder
    {
        return self::query()
            ->whereNull('user_id')
            ->whereNull('telegram_group_id')
            ->whereNull('subscription_id')
            ->where('created_at', '<', now()->subDays(1));
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Only payments that count for stats/quota (parsed, not duplicate).
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query
            ->where('parsed_successfully', true)
            ->where('is_duplicate', false);
    }

    public function scopeForGroup(Builder $query, string $telegramGroupId): Builder
    {
        return $query->where('telegram_group_id', $telegramGroupId);
    }

    public function scopeForSubscription(Builder $query, string $subscriptionId): Builder
    {
        return $query->where('subscription_id', $subscriptionId);
    }

    public function scopeCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    // ── Relations ─────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function telegramGroup()
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id', 'telegramGroupsID');
    }

    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id', 'userSubscriptionsID');
    }
}