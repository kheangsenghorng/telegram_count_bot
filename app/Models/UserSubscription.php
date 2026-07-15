<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'renewal_reminded_at',
        'status',
        'payment_method',
        'transaction_id',
    ];


    protected $casts = [
        'override_payment_limit' => 'integer',
        'override_group_limit'   => 'integer',
        'payment_used'           => 'integer',
        'group_used'             => 'integer',
        'starts_at'              => 'datetime',
        'ends_at'                => 'datetime',
        'renewal_reminded_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($subscription) {
            if (! $subscription->userSubscriptionsID) {
                $subscription->userSubscriptionsID = (string) Str::uuid();
            }

            if (! $subscription->subscription_key) {
                $subscription->subscription_key = self::generateSubscriptionKey();
            }

            if ($subscription->payment_used === null) {
                $subscription->payment_used = 0;
            }

            if ($subscription->group_used === null) {
                $subscription->group_used = 0;
            }
        });
    }

    

    // ── Relations ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'uuid'
        );
    }



    

    public function package(): BelongsTo
    {
        return $this->belongsTo(
            Package::class,
            'package_id',
            'packagesID'
        );
    }

    // ── Query scopes ─────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }

    public function scopeForUser(Builder $query, string $userUuid): Builder
    {
        return $query->where('user_id', $userUuid);
    }

    // ── Status helpers ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    public function isLifetime(): bool
    {
        return $this->ends_at === null;
    }

    public function isExpired(): bool
    {
        return ! $this->isLifetime()
            && $this->ends_at !== null
            && $this->ends_at->isPast();
    }

    // ── Effective limits ─────────────────────────────────────────────────

    /**
     * Effective payment limit.
     *
     * NULL = unlimited.
     *
     * Example:
     * package payment_limit = 4000
     * old remaining = 100
     * override_payment_limit = 4100
     */
    public function effectivePaymentLimit(): ?int
    {
        if ($this->override_payment_limit !== null) {
            return (int) $this->override_payment_limit;
        }

        $package = $this->package;

        if (! $package) {
            return 0;
        }

        if (method_exists($package, 'isUnlimitedPayments') && $package->isUnlimitedPayments()) {
            return null;
        }

        return (int) $package->payment_limit;
    }

    /**
     * Effective group limit.
     *
     * NULL = unlimited.
     */
    public function effectiveGroupLimit(): ?int
    {
        if ($this->override_group_limit !== null) {
            return (int) $this->override_group_limit;
        }

        $package = $this->package;

        if (! $package) {
            return 0;
        }

        if (method_exists($package, 'isUnlimitedGroups') && $package->isUnlimitedGroups()) {
            return null;
        }

        return (int) $package->group_limit;
    }

    public function isUnlimitedPayments(): bool
    {
        return $this->effectivePaymentLimit() === null;
    }

    public function isUnlimitedGroups(): bool
    {
        return $this->effectiveGroupLimit() === null;
    }

    // ── Remaining quota ──────────────────────────────────────────────────

    /**
     * Remaining payment quota.
     *
     * NULL = unlimited.
     */
    public function remainingPayments(): ?int
    {
        $limit = $this->effectivePaymentLimit();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - (int) $this->payment_used);
    }

    /**
     * Remaining group quota.
     *
     * NULL = unlimited.
     */
    public function remainingGroups(): ?int
    {
        $limit = $this->effectiveGroupLimit();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - (int) $this->group_used);
    }

    public function hasPaymentQuota(): bool
    {
        return $this->isUnlimitedPayments()
            || ((int) $this->remainingPayments() > 0);
    }

    public function hasGroupQuota(): bool
    {
        return $this->isUnlimitedGroups()
            || ((int) $this->remainingGroups() > 0);
    }

    // ── Consume quota ────────────────────────────────────────────────────

    /**
     * Call every time a payment message is captured.
     */
    public function consumePayment(): bool
    {
        if ($this->isUnlimitedPayments()) {
            return true;
        }

        if (! $this->hasPaymentQuota()) {
            return false;
        }

        $this->increment('payment_used');

        return true;
    }

    /**
     * Call when user adds/registers a group.
     */
    public function consumeGroup(): bool
    {
        if ($this->isUnlimitedGroups()) {
            return true;
        }

        if (! $this->hasGroupQuota()) {
            return false;
        }

        $this->increment('group_used');

        return true;
    }

    // ── Upgrade helpers ──────────────────────────────────────────────────

    /**
     * Get payment quota that can be carried to new package.
     */
    public function carryOverPayments(): int
    {
        $remaining = $this->remainingPayments();

        if ($remaining === null) {
            return 0;
        }

        return max(0, (int) $remaining);
    }

    /**
     * Get current used groups.
     * When upgrading, existing groups should remain counted.
     */
    public function carryOverGroupsUsed(): int
    {
        return max(0, (int) $this->group_used);
    }

    // ── Lookups ──────────────────────────────────────────────────────────

    /**
     * User current active, non-expired subscription.
     */
    public static function activeFor(string $userUuid): ?self
    {
        return self::query()
            ->with('package')
            ->forUser($userUuid)
            ->active()
            ->latest('starts_at')
            ->first();
    }

    /**
     * Prevent duplicate activation from same transaction.
     */
    public static function existsForTransaction(string $transactionId): bool
    {
        return self::query()
            ->where('transaction_id', $transactionId)
            ->exists();
    }

    /**
     * Generate unique subscription key.
     */
    public static function generateSubscriptionKey(): string
    {
        do {
            $key = 'SUB-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (self::query()->where('subscription_key', $key)->exists());

        return $key;
    }
}